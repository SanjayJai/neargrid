<?php

declare(strict_types=1);

namespace App\Domains\Taxonomy\Services;

use App\Domains\Seo\Services\RedirectLifecycleService;
use App\Domains\Seo\Services\SeoPayloadResolver;
use App\Domains\Taxonomy\Exceptions\CategoryException;
use App\Domains\Taxonomy\Models\Category;
use App\Domains\Taxonomy\Models\CategoryCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

final class CategoryTreeService
{
    public const SUPPORTED_LOCALES = ['en', 'ta'];

    private const CACHE_VERSION_KEY = 'taxonomy:tree:version';

    public function __construct(
        private readonly RedirectLifecycleService $redirects,
        private readonly SeoPayloadResolver $seoResolver,
    ) {}

    public function invalidateCache(): void
    {
        $current = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        Cache::forever(self::CACHE_VERSION_KEY, $current + 1);
    }

    /**
     * @return array{categories: list<array<string, mixed>>, collections: list<array<string, mixed>>}
     */
    public function getPublishedTree(string $locale = 'en'): array
    {
        $cleanLocale = in_array($locale, self::SUPPORTED_LOCALES, true) ? $locale : 'en';
        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        $ttl = max(60, (int) config('taxonomy.cache_ttl_seconds', 3600));
        $cacheKey = "taxonomy:tree:v{$version}:{$cleanLocale}";

        /** @var array{categories: list<array<string, mixed>>, collections: list<array<string, mixed>>} $tree */
        $tree = Cache::remember($cacheKey, $ttl, function () use ($cleanLocale): array {
            $all = Category::query()
                ->published()
                ->with(['translations' => static fn ($q) => $q->whereIn('locale', [$cleanLocale, 'en'])])
                ->orderBy('depth')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();

            $byParent = $all->groupBy(static fn (Category $c): int => (int) ($c->parent_id ?? 0));

            $build = function (int $parentId, string $prefixPath) use (&$build, $byParent, $cleanLocale): array {
                /** @var Collection<int, Category> $nodes */
                $nodes = $byParent->get($parentId, collect());

                return $nodes->map(function (Category $cat) use (&$build, $prefixPath, $cleanLocale): array {
                    $path = $prefixPath === '' ? "/c/{$cat->slug}" : "{$prefixPath}/{$cat->slug}";

                    return [
                        'public_id' => $cat->public_id,
                        'slug' => $cat->slug,
                        'path' => $path,
                        'name' => $cat->translatedName($cleanLocale),
                        'name_en' => $cat->name,
                        'node_type' => $cat->node_type,
                        'depth' => $cat->depth,
                        'schema_org_type' => $cat->schema_org_type,
                        'icon_name' => $cat->icon_name,
                        'is_assignable' => $cat->is_assignable,
                        'has_landing_page' => $cat->has_landing_page,
                        'show_on_homepage' => $cat->show_on_homepage,
                        'is_popular' => $cat->is_popular,
                        'is_featured' => $cat->is_featured,
                        'sort_order' => $cat->sort_order,
                        'children' => $build((int) $cat->id, $path),
                    ];
                })->values()->all();
            };

            $collections = CategoryCollection::query()
                ->published()
                ->with(['categories' => static fn ($q) => $q->published()->with(['translations'])])
                ->orderBy('sort_order')
                ->orderBy('name_en')
                ->get()
                ->map(static fn (CategoryCollection $col): array => [
                    'public_id' => $col->public_id,
                    'slug' => $col->slug,
                    'path' => "/c/collections/{$col->slug}",
                    'name' => $col->translatedName($cleanLocale),
                    'name_en' => $col->name_en,
                    'icon_name' => $col->icon_name,
                    'show_on_homepage' => $col->show_on_homepage,
                    'is_popular' => $col->is_popular,
                    'category_slugs' => $col->categories->pluck('slug')->values()->all(),
                ])
                ->values()
                ->all();

            return [
                'categories' => $build(0, ''),
                'collections' => $collections,
            ];
        });

        return $tree;
    }

    /**
     * Resolve a /c/{slugPath} category or collection landing payload, or 301 redirect target.
     *
     * @return array<string, mixed>
     */
    public function resolveLanding(string $slugPath, string $locale = 'en', ?int $domainId = null): array
    {
        $cleanLocale = in_array($locale, self::SUPPORTED_LOCALES, true) ? $locale : 'en';
        $segments = array_values(array_filter(explode('/', trim($slugPath, '/'))));
        if ($segments === []) {
            throw new CategoryException('CATEGORY_NOT_FOUND', 'Category path is empty.', Response::HTTP_NOT_FOUND);
        }

        $fullPath = '/c/'.implode('/', $segments);

        // Collection path: /c/collections/{slug}
        if (count($segments) === 2 && $segments[0] === 'collections') {
            $collection = CategoryCollection::query()
                ->published()
                ->where('slug', $segments[1])
                ->with(['categories' => static fn ($q) => $q->published()->with('translations')])
                ->first();

            if ($collection === null) {
                $redirectTarget = $this->redirects->resolvePath($fullPath, $domainId);
                if ($redirectTarget !== null) {
                    return ['redirect' => true, 'http_status' => 301, 'target_path' => $redirectTarget];
                }

                throw new CategoryException('COLLECTION_NOT_FOUND', 'Category collection not found.', Response::HTTP_NOT_FOUND);
            }

            $canonicalPath = "/c/collections/{$collection->slug}";

            return [
                'redirect' => false,
                'kind' => 'collection',
                'collection' => [
                    'public_id' => $collection->public_id,
                    'slug' => $collection->slug,
                    'name' => $collection->translatedName($cleanLocale),
                    'description' => $cleanLocale === 'ta' && $collection->description_ta ? $collection->description_ta : $collection->description_en,
                ],
                'seo' => $this->seoResolver->resolveCategoryOrCollection($collection, $cleanLocale, $canonicalPath, [], $domainId),
            ];
        }

        // Category path: resolve final segment and verify full ancestor chain if multi-segment
        $leafSlug = $segments[count($segments) - 1];
        $category = Category::query()
            ->published()
            ->where('slug', $leafSlug)
            ->with(['translations', 'children' => static fn ($q) => $q->published()->with('translations'), 'filterConfigs', 'profileSections'])
            ->first();

        if ($category === null) {
            $redirectTarget = $this->redirects->resolvePath($fullPath, $domainId) ?? $this->redirects->resolvePath("/c/{$leafSlug}", $domainId);
            if ($redirectTarget !== null) {
                return ['redirect' => true, 'http_status' => 301, 'target_path' => $redirectTarget];
            }

            throw new CategoryException('CATEGORY_NOT_FOUND', 'Category not found.', Response::HTTP_NOT_FOUND);
        }

        $canonicalPath = $this->canonicalPathFor($category);
        if ($canonicalPath !== $fullPath) {
            return ['redirect' => true, 'http_status' => 301, 'target_path' => $canonicalPath];
        }

        $breadcrumbs = $this->buildBreadcrumbs($category, $cleanLocale);

        return [
            'redirect' => false,
            'kind' => 'category',
            'category' => [
                'public_id' => $category->public_id,
                'slug' => $category->slug,
                'path' => $canonicalPath,
                'name' => $category->translatedName($cleanLocale),
                'node_type' => $category->node_type,
                'depth' => $category->depth,
                'schema_org_type' => $category->schema_org_type,
                'has_landing_page' => $category->has_landing_page,
                'children' => $category->children->map(fn (Category $c): array => [
                    'public_id' => $c->public_id,
                    'slug' => $c->slug,
                    'path' => $this->canonicalPathFor($c),
                    'name' => $c->translatedName($cleanLocale),
                    'node_type' => $c->node_type,
                ])->values()->all(),
            ],
            'breadcrumbs' => $breadcrumbs,
            'seo' => $this->seoResolver->resolveCategoryOrCollection($category, $cleanLocale, $canonicalPath, $breadcrumbs, $domainId),
        ];
    }

    public function canonicalPathFor(Category $category): string
    {
        $slugs = [];
        $current = $category;
        $guard = 0;

        while ($current !== null && $guard < 10) {
            array_unshift($slugs, $current->slug);
            $current = $current->parent_id ? Category::query()->withTrashed()->find($current->parent_id) : null;
            $guard++;
        }

        return '/c/'.implode('/', $slugs);
    }

    /**
     * @return list<array{name: string, url: string}>
     */
    private function buildBreadcrumbs(Category $category, string $locale): array
    {
        $baseUrl = rtrim((string) config('app.url', 'http://localhost:3000'), '/');
        $chain = [];
        $current = $category;
        $guard = 0;

        while ($current !== null && $guard < 10) {
            array_unshift($chain, $current);
            $current = $current->parent_id ? Category::query()->find($current->parent_id) : null;
            $guard++;
        }

        $breadcrumbs = [];
        $path = '/c';
        foreach ($chain as $node) {
            $path .= "/{$node->slug}";
            $breadcrumbs[] = [
                'name' => $node->translatedName($locale),
                'url' => "{$baseUrl}{$path}",
            ];
        }

        return $breadcrumbs;
    }
}
