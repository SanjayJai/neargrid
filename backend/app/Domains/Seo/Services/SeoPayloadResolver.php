<?php

declare(strict_types=1);

namespace App\Domains\Seo\Services;

use App\Domains\Seo\Models\SeoMetadata;
use App\Domains\Taxonomy\Models\Category;
use App\Domains\Taxonomy\Models\CategoryCollection;

final class SeoPayloadResolver
{
    public function __construct(
        private readonly IndexabilityGate $indexabilityGate,
        private readonly SchemaOrgBuilder $schemaBuilder,
    ) {}

    /**
     * @param  list<array{name: string, url: string}>  $breadcrumbs
     * @return array<string, mixed>
     */
    public function resolveCategoryOrCollection(
        Category|CategoryCollection $entity,
        string $locale,
        string $canonicalPath,
        array $breadcrumbs = [],
        ?int $domainId = null,
        ?int $listingCount = null,
    ): array {
        $entityType = $entity instanceof Category ? 'category' : 'category_collection';
        $scopeKey = SeoMetadata::scopeKeyFor($domainId);

        $override = SeoMetadata::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entity->getKey())
            ->where('locale', $locale)
            ->whereIn('scope_key', array_unique([$scopeKey, 'global']))
            ->orderByRaw("CASE WHEN scope_key = ? THEN 0 ELSE 1 END", [$scopeKey])
            ->first();

        $baseUrl = rtrim((string) config('app.url', 'http://localhost:3000'), '/');
        $canonicalUrl = $override?->canonical_override_url ?: "{$baseUrl}{$canonicalPath}";

        if ($entity instanceof Category) {
            $translation = $entity->translationFor($locale);
            $fallbackTitle = $translation?->seo_title_template
                ? str_replace('{city}', 'India', $translation->seo_title_template)
                : "{$entity->translatedName($locale)} | NearGrid";
            $fallbackDesc = $translation?->seo_description_template
                ? str_replace('{city}', 'India', $translation->seo_description_template)
                : ($translation?->description ?? "Explore verified {$entity->translatedName($locale)} listings on NearGrid.");
        } else {
            $fallbackTitle = "{$entity->translatedName($locale)} | NearGrid";
            $fallbackDesc = ($locale === 'ta' && $entity->description_ta)
                ? $entity->description_ta
                : ($entity->description_en ?? "Curated {$entity->translatedName($locale)} collection on NearGrid.");
        }

        $title = $override?->meta_title ?: $fallbackTitle;
        $description = $override?->meta_description ?: $fallbackDesc;

        $indexability = $this->indexabilityGate->evaluate([
            'is_active' => (bool) $entity->is_active,
            'lifecycle_status' => $entity->lifecycle_status,
            'is_soft_deleted' => $entity->trashed(),
            'has_landing_page' => $entity instanceof Category ? (bool) $entity->has_landing_page : true,
            'listing_count' => $listingCount,
            'min_listings' => 0,
            'override_robots_index' => $override?->robots_index,
            'override_robots_follow' => $override?->robots_follow,
        ]);

        $alternates = [
            'en-IN' => "{$baseUrl}{$canonicalPath}",
            'x-default' => "{$baseUrl}{$canonicalPath}",
        ];
        if ($entity instanceof Category && $entity->translationFor('ta') !== null) {
            $alternates['ta-IN'] = "{$baseUrl}{$canonicalPath}?locale=ta";
        } elseif ($entity instanceof CategoryCollection && $entity->name_ta !== null && $entity->name_ta !== '') {
            $alternates['ta-IN'] = "{$baseUrl}{$canonicalPath}?locale=ta";
        }

        return [
            'title' => $title,
            'description' => $description,
            'canonical_url' => $canonicalUrl,
            'robots' => $indexability['robots'],
            'indexable' => $indexability['index'],
            'indexability_reason' => $indexability['reason'],
            'alternates' => $alternates,
            'open_graph' => [
                'title' => $override?->og_title ?: $title,
                'description' => $override?->og_description ?: $description,
                'image_url' => $override?->og_image_url,
                'image_alt' => $override?->og_image_alt ?: $title,
                'url' => $canonicalUrl,
            ],
            'json_ld' => $this->schemaBuilder->buildCategoryLanding(
                $entity,
                $canonicalUrl,
                $title,
                $description,
                $breadcrumbs,
            ),
        ];
    }
}
