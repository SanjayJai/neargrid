<?php

declare(strict_types=1);

namespace App\Domains\Search\Services;

use App\Domains\Search\DTOs\SearchQuery;
use App\Domains\Search\Enums\SearchSortOption;

/**
 * Produces deterministic canonical URL & robots indexation guidance per docs/12-seo-architecture.md.
 * Prevents duplicate content from arbitrary filter combinations, sorting, and deep pagination.
 */
final class SearchSeoAdvisor
{
    /**
     * @return array{
     *     canonical_path: string,
     *     canonical_url: string,
     *     robots: string,
     *     is_indexable: bool,
     *     noindex_reason: ?string
     * }
     */
    public function evaluate(SearchQuery $query, int $totalOrganicResults): array
    {
        $siteUrl = rtrim((string) config('search.seo.site_url', 'http://localhost:3000'), '/');
        $maxIndexedPage = (int) config('search.seo.max_indexed_page', 50);
        $minLocalityCategory = (int) config('search.seo.min_listings_for_locality_category_index', 3);

        $hasArbitraryFilters = $query->minRating !== null
            || $query->verifiedOnly
            || $query->openNow
            || $query->amenitySlugs !== []
            || $query->paymentMethodSlugs !== []
            || $query->hasCoordinates()
            || $query->radiusKm !== null
            || count($query->localitySlugs) > 1;

        $hasNonDefaultSort = $query->sort !== SearchSortOption::Relevance;
        $hasFreeText = $query->rawQuery !== null && trim($query->rawQuery) !== '';

        // Determine canonical base path (city / city+category / city+locality+category)
        $basePath = '/search';
        $pageKind = 'search';

        if ($query->citySlug !== null) {
            if ($query->categorySlug !== null && count($query->localitySlugs) === 1) {
                $basePath = "/{$query->citySlug}/{$query->localitySlugs[0]}/{$query->categorySlug}";
                $pageKind = 'city_locality_category';
            } elseif ($query->categorySlug !== null) {
                $basePath = "/{$query->citySlug}/{$query->categorySlug}";
                $pageKind = 'city_category';
            } elseif ($query->localitySlugs === []) {
                $basePath = "/{$query->citySlug}";
                $pageKind = 'city';
            }
        }

        $isIndexable = true;
        $noindexReason = null;

        if ($query->isNearbyMode) {
            $isIndexable = false;
            $noindexReason = 'nearby_coordinate_query';
        } elseif ($hasFreeText) {
            $isIndexable = false;
            $noindexReason = 'free_text_search_query';
        } elseif ($pageKind === 'search') {
            $isIndexable = false;
            $noindexReason = 'non_canonical_search_combination';
        } elseif ($hasArbitraryFilters) {
            $isIndexable = false;
            $noindexReason = 'filtered_facet_combination';
        } elseif ($hasNonDefaultSort) {
            $isIndexable = false;
            $noindexReason = 'non_default_sort_parameter';
        } elseif ($query->page > $maxIndexedPage) {
            $isIndexable = false;
            $noindexReason = 'pagination_exceeds_index_limit';
        } elseif ($pageKind === 'city_locality_category' && $totalOrganicResults < $minLocalityCategory) {
            $isIndexable = false;
            $noindexReason = 'thin_locality_category_page';
        } elseif ($totalOrganicResults < 1) {
            $isIndexable = false;
            $noindexReason = 'zero_active_listings';
        }

        // Page 1 canonical NEVER includes ?page=1; pages 2..50 self-reference only when indexable.
        $canonicalPath = $basePath;
        if ($isIndexable && $query->page >= 2 && $query->page <= $maxIndexedPage) {
            $canonicalPath = "{$basePath}?page={$query->page}";
        }

        return [
            'canonical_path' => $canonicalPath,
            'canonical_url' => $siteUrl.$canonicalPath,
            'robots' => $isIndexable ? 'index, follow' : 'noindex, follow',
            'is_indexable' => $isIndexable,
            'noindex_reason' => $noindexReason,
        ];
    }
}
