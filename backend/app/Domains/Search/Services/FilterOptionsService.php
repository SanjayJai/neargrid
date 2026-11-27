<?php

declare(strict_types=1);

namespace App\Domains\Search\Services;

use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Services\AttributeTypeRegistry;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Search\DTOs\FilterOptionsResult;
use App\Domains\Search\DTOs\FilterQuery;
use App\Domains\Search\Enums\SearchSortOption;
use App\Domains\Taxonomy\Models\Amenity;
use App\Domains\Taxonomy\Models\Category;
use App\Domains\Taxonomy\Models\CategoryAttributeDefinition;
use App\Domains\Taxonomy\Models\CategoryFilterConfig;
use App\Domains\Taxonomy\Models\PaymentMethod;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class FilterOptionsService
{
    public function getFilters(FilterQuery $query): FilterOptionsResult
    {
        $storeName = (string) config('search.autocomplete.cache_store', config('cache.default', 'array'));
        $ttl = max(15, (int) config('search.cache.filters_ttl_seconds', 120));
        $catVersion = $this->categoryVersionToken($storeName, $query->categorySlug);
        $cacheKey = 'search:filters:v1:'.sha1(implode('|', [
            $query->locale,
            $query->citySlug ?? 'all',
            $query->categorySlug ?? 'all',
            $catVersion,
        ]));

        $builder = fn (): array => $this->computeFilters($query);

        try {
            /** @var array<string, mixed> $data */
            $data = Cache::store($storeName)->remember($cacheKey, $ttl, $builder);
        } catch (Throwable) {
            $data = $builder();
        }

        return new FilterOptionsResult(
            cities: $data['cities'],
            localities: $data['localities'],
            categories: $data['categories'],
            amenities: $data['amenities'],
            paymentMethods: $data['payment_methods'],
            ratingOptions: $data['rating_options'],
            sortOptions: $data['sort_options'],
            radius: $data['radius'],
            verifiedActiveCount: (int) $data['verified_active_count'],
            totalActiveCount: (int) $data['total_active_count'],
            categoryFilters: is_array($data['category_filters'] ?? null) ? $data['category_filters'] : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function computeFilters(FilterQuery $query): array
    {
        $locales = array_values(array_unique([$query->locale, 'en']));

        $selectedCity = null;
        if ($query->citySlug !== null && $query->citySlug !== '') {
            $selectedCity = City::query()
                ->where('slug', $query->citySlug)
                ->where('is_active', true)
                ->first();
        }

        $selectedCategory = null;
        $categoryIds = [];
        if ($query->categorySlug !== null && $query->categorySlug !== '') {
            $selectedCategory = Category::query()
                ->where('slug', $query->categorySlug)
                ->where('is_active', true)
                ->first();
            if ($selectedCategory !== null) {
                $childIds = Category::query()
                    ->where('parent_id', $selectedCategory->id)
                    ->where('is_active', true)
                    ->pluck('id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all();
                $categoryIds = [$selectedCategory->id, ...$childIds];
            }
        }

        $activeBase = Business::query()
            ->published()
            ->when($selectedCity !== null, static fn ($q) => $q->where('city_id', $selectedCity->id))
            ->when($categoryIds !== [], static function ($q) use ($categoryIds): void {
                $q->where(static function ($inner) use ($categoryIds): void {
                    $inner->whereIn('primary_category_id', $categoryIds)
                        ->orWhereHas('categories', static fn ($c) => $c->whereIn('categories.id', $categoryIds));
                });
            });

        $totalActiveCount = (clone $activeBase)->count();
        $verifiedActiveCount = (clone $activeBase)->where('is_verified', true)->count();

        $cities = City::query()
            ->where('is_active', true)
            ->with(['translations' => static fn ($q) => $q->whereIn('locale', $locales)])
            ->orderByDesc('is_popular')
            ->orderBy('name')
            ->get()
            ->map(static fn (City $city): array => [
                'slug' => $city->slug,
                'name' => $city->translatedName($query->locale),
                'is_popular' => $city->is_popular,
            ])->values()->all();

        $localities = [];
        if ($selectedCity !== null) {
            $localityCounts = (clone $activeBase)
                ->selectRaw('locality_id, COUNT(*) as listing_count')
                ->groupBy('locality_id')
                ->pluck('listing_count', 'locality_id');

            $localities = Locality::query()
                ->where('city_id', $selectedCity->id)
                ->where('is_active', true)
                ->with(['translations' => static fn ($q) => $q->whereIn('locale', $locales)])
                ->orderBy('name')
                ->get()
                ->map(static fn (Locality $loc): array => [
                    'slug' => $loc->slug,
                    'name' => $loc->translatedName($query->locale),
                    'pincode' => $loc->pincode,
                    'active_listing_count' => (int) ($localityCounts[$loc->id] ?? 0),
                ])->values()->all();
        }

        $categories = Category::query()
            ->where('is_active', true)
            ->with(['translations' => static fn ($q) => $q->whereIn('locale', $locales)])
            ->orderBy('depth')
            ->orderBy('sort_order')
            ->get()
            ->map(static fn (Category $category): array => [
                'public_id' => $category->public_id,
                'slug' => $category->slug,
                'name' => $category->translatedName($query->locale),
                'depth' => $category->depth,
                'is_featured' => $category->is_featured,
            ])->values()->all();

        $amenities = Amenity::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['slug', 'name', 'icon_name'])
            ->map(static fn (Amenity $a): array => [
                'slug' => $a->slug,
                'name' => $a->name,
                'icon_name' => $a->icon_name,
            ])->values()->all();

        $paymentMethods = PaymentMethod::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['slug', 'name'])
            ->map(static fn (PaymentMethod $pm): array => [
                'slug' => $pm->slug,
                'name' => $pm->name,
            ])->values()->all();

        return [
            'cities' => $cities,
            'localities' => $localities,
            'categories' => $categories,
            'amenities' => $amenities,
            'payment_methods' => $paymentMethods,
            'rating_options' => [
                ['value' => 4.5, 'label' => '4.5 & above'],
                ['value' => 4.0, 'label' => '4.0 & above'],
                ['value' => 3.5, 'label' => '3.5 & above'],
                ['value' => 3.0, 'label' => '3.0 & above'],
            ],
            'sort_options' => array_map(
                static fn (SearchSortOption $opt): array => [
                    'value' => $opt->value,
                    'label' => match ($opt) {
                        SearchSortOption::Relevance => 'Relevance',
                        SearchSortOption::Rating => 'Highest Rated',
                        SearchSortOption::Distance => 'Nearest First',
                        SearchSortOption::Popularity => 'Most Popular',
                        SearchSortOption::Newest => 'Newest',
                    },
                ],
                SearchSortOption::cases()
            ),
            'radius' => [
                'min_km' => (float) config('search.geo.min_radius_km', 0.5),
                'max_km' => (float) config('search.geo.max_radius_km', 50.0),
                'default_km' => (float) config('search.geo.default_radius_km', 10.0),
            ],
            'verified_active_count' => $verifiedActiveCount,
            'total_active_count' => $totalActiveCount,
            'category_filters' => $this->resolveCategoryFilters($selectedCategory, $query->locale),
        ];
    }

    /**
     * Resolve enabled category_filter_configs paired with active, is_filterable
     * category_attribute_definitions for the selected category.
     *
     * @return list<array<string, mixed>>
     */
    private function resolveCategoryFilters(?Category $category, string $locale): array
    {
        if ($category === null) {
            return [];
        }

        $configs = CategoryFilterConfig::query()
            ->where('category_id', $category->id)
            ->where('is_enabled', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($configs->isEmpty()) {
            return [];
        }

        $definitions = CategoryAttributeDefinition::query()
            ->where('category_id', $category->id)
            ->where('is_active', true)
            ->where('is_filterable', true)
            ->get()
            ->keyBy('attribute_key');

        if ($definitions->isEmpty()) {
            return [];
        }

        $registry = app(AttributeTypeRegistry::class);
        $filters = [];

        foreach ($configs as $config) {
            /** @var CategoryAttributeDefinition|null $def */
            $def = $definitions->get($config->filter_key);
            if ($def === null) {
                continue;
            }

            $optionsJson = $def->options_json ?? [];
            $choices = in_array($def->data_type, ['enum', 'multi_enum'], true)
                ? array_map(
                    static fn (string $choice): array => ['value' => $choice, 'label' => $choice],
                    $registry->extractChoices($def),
                )
                : [];

            $label = $locale === 'ta' && is_string($config->label_ta) && trim($config->label_ta) !== ''
                ? $config->label_ta
                : $config->label_en;

            $filters[] = [
                'filter_key' => $def->attribute_key,
                'filter_type' => $config->filter_type,
                'data_type' => $def->data_type,
                'label' => $label,
                'label_en' => $config->label_en,
                'label_ta' => $config->label_ta,
                'unit' => $def->unit,
                'options' => $choices,
                'min' => isset($optionsJson['min']) && is_numeric($optionsJson['min']) ? (float) $optionsJson['min'] : null,
                'max' => isset($optionsJson['max']) && is_numeric($optionsJson['max']) ? (float) $optionsJson['max'] : null,
                'sort_order' => $config->sort_order,
            ];
        }

        return $filters;
    }

    /**
     * Targeted O(1) category filter cache invalidation using per-category version tokens
     * (avoids broad Redis key scans).
     */
    public function invalidateForCategory(Category $category): void
    {
        $storeName = (string) config('search.autocomplete.cache_store', config('cache.default', 'array'));
        $categoryIds = array_values(array_unique(array_filter([
            (int) $category->id,
            $category->parent_id !== null ? (int) $category->parent_id : null,
        ])));

        try {
            $store = Cache::store($storeName);
            foreach ($categoryIds as $catId) {
                $versionKey = $this->categoryVersionKey($catId);
                $next = ((int) $store->get($versionKey, 1)) + 1;
                $store->forever($versionKey, $next);
            }
        } catch (Throwable) {
        }
    }

    private function categoryVersionToken(string $storeName, ?string $categorySlug): string
    {
        if ($categorySlug === null || $categorySlug === '') {
            return '0';
        }

        $catId = Category::query()
            ->where('slug', $categorySlug)
            ->where('is_active', true)
            ->value('id');

        if ($catId === null) {
            return '0';
        }

        try {
            return (string) Cache::store($storeName)->get($this->categoryVersionKey((int) $catId), 1);
        } catch (Throwable) {
            return '1';
        }
    }

    private function categoryVersionKey(int $categoryId): string
    {
        return "search:filters:cat_ver:{$categoryId}";
    }
}
