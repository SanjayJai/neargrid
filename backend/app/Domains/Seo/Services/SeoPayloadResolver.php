<?php

declare(strict_types=1);

namespace App\Domains\Seo\Services;

use App\Domains\Directory\Models\Business;
use App\Domains\Domains\Models\Domain;
use App\Domains\Domains\Services\DomainResolutionService;
use App\Domains\Seo\Models\SeoMetadata;
use App\Domains\Taxonomy\Models\Category;
use App\Domains\Taxonomy\Models\CategoryCollection;

final class SeoPayloadResolver
{
    public function __construct(
        private readonly IndexabilityGate $indexabilityGate,
        private readonly SchemaOrgBuilder $schemaBuilder,
        private readonly DomainResolutionService $domainResolver,
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

        $domainContext = $this->resolveAuthoritativeContext(
            requestDomainId: $domainId,
            entityCanonicalDomain: null,
            isCitySpecific: false,
        );
        $baseUrl = $domainContext['origin'];
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
            'override_robots_index' => $domainContext['forceNoIndex'] ? false : $override?->robots_index,
            'override_robots_follow' => $domainContext['forceNoIndex'] ? false : $override?->robots_follow,
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

    /**
     * @return array<string, mixed>
     */
    public function resolveBusinessProfile(
        Business $business,
        string $locale,
        ?int $domainId = null,
    ): array {
        $citySlug = $business->city?->slug ?? 'india';
        $canonicalPath = "/{$citySlug}/business/{$business->slug}";

        $domainContext = $this->resolveAuthoritativeContext(
            requestDomainId: $domainId,
            entityCanonicalDomain: $business->canonicalDomain,
            isCitySpecific: true,
            entityCityId: (int) $business->city_id,
        );

        $baseUrl = $domainContext['origin'];
        $canonicalUrl = "{$baseUrl}{$canonicalPath}";
        $title = "{$business->name} | NearGrid";
        $description = $business->tagline ?: "Explore verified details for {$business->name} on NearGrid.";

        $indexability = $this->indexabilityGate->evaluate([
            'is_active' => $business->status?->value === 'active',
            'lifecycle_status' => 'published',
            'is_soft_deleted' => $business->trashed(),
            'has_landing_page' => true,
            'listing_count' => 1,
            'min_listings' => 0,
            'override_robots_index' => $domainContext['forceNoIndex'] ? false : true,
            'override_robots_follow' => $domainContext['forceNoIndex'] ? false : true,
        ]);

        return [
            'title' => $title,
            'description' => $description,
            'canonical_url' => $canonicalUrl,
            'robots' => $indexability['robots'],
            'indexable' => $indexability['index'],
            'indexability_reason' => $indexability['reason'],
        ];
    }

    /**
     * Resolves the authoritative canonical origin and whether noindex,nofollow must be forced.
     *
     * @return array{origin: string, forceNoIndex: bool}
     */
    private function resolveAuthoritativeContext(
        ?int $requestDomainId,
        ?Domain $entityCanonicalDomain = null,
        bool $isCitySpecific = false,
        ?int $entityCityId = null,
    ): array {
        $requestDomain = $requestDomainId !== null
            ? Domain::query()->with('canonicalDomain')->find($requestDomainId)
            : null;
        $primaryDomain = $this->domainResolver->resolvePrimaryDomain();

        $forceNoIndex = false;
        if ($requestDomain !== null && ! $requestDomain->isEffectivelyIndexable()) {
            $forceNoIndex = true;
        }

        // 1. Explicit entity canonical domain takes precedence when eligible (active + verified)
        if ($this->isEligibleCanonicalDomain($entityCanonicalDomain)) {
            if ($requestDomain !== null && (int) $requestDomain->id !== (int) $entityCanonicalDomain->id) {
                $forceNoIndex = $forceNoIndex || ($requestDomain->canonical_domain_id !== null);
            }

            return [
                'origin' => $entityCanonicalDomain->origin(),
                'forceNoIndex' => $forceNoIndex,
            ];
        }

        // 2. City portals may self-canonicalize eligible, indexable city-specific pages
        if (
            $isCitySpecific
            && $requestDomain !== null
            && $requestDomain->domain_type === 'city_portal'
            && $requestDomain->is_canonical_for_location
            && $requestDomain->isEffectivelyIndexable()
            && ($entityCityId === null || $requestDomain->default_city_id === null || (int) $requestDomain->default_city_id === $entityCityId)
        ) {
            return [
                'origin' => $requestDomain->origin(),
                'forceNoIndex' => false,
            ];
        }

        // 3. Alias domain pointing to an eligible canonical target domain
        if ($requestDomain !== null && $this->isEligibleCanonicalDomain($requestDomain->canonicalDomain)) {
            return [
                'origin' => $requestDomain->canonicalDomain->origin(),
                'forceNoIndex' => true,
            ];
        }

        // 4. Fallback to active verified primary domain (never unverified/suspended request domain)
        if ($this->isEligibleCanonicalDomain($primaryDomain)) {
            return [
                'origin' => $primaryDomain->origin(),
                'forceNoIndex' => $forceNoIndex,
            ];
        }

        return [
            'origin' => rtrim((string) config('app.url', 'http://localhost:3000'), '/'),
            'forceNoIndex' => $forceNoIndex,
        ];
    }

    private function isEligibleCanonicalDomain(?Domain $domain): bool
    {
        return $domain !== null
            && ! $domain->trashed()
            && $domain->status === 'active'
            && $domain->verification_status === 'verified';
    }
}
