<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Search;

use App\Domains\Location\Services\LocationRedirectResolver;
use App\Domains\Search\Contracts\SearchEngineInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Search\NearbySearchRequest;
use App\Http\Requests\Api\V1\Search\SearchBusinessesRequest;
use App\Http\Requests\Api\V1\Search\SearchFiltersRequest;
use App\Http\Requests\Api\V1\Search\SearchSuggestionsRequest;
use App\Http\Resources\Api\V1\Search\SearchBusinessHitResource;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

final class SearchController extends Controller
{
    public function __construct(
        private readonly SearchEngineInterface $searchEngine,
        private readonly LocationRedirectResolver $locationRedirectResolver,
    ) {}

    public function search(SearchBusinessesRequest $request): JsonResponse
    {
        $query = $request->toSearchQuery(isNearbyMode: false);
        $singleLocality = count($query->localitySlugs) === 1 ? $query->localitySlugs[0] : null;
        $redirectTarget = $this->locationRedirectResolver->resolveDirectoryRedirect(
            citySlug: $query->citySlug,
            localitySlug: $singleLocality,
            categorySlug: $query->categorySlug,
        );

        if ($redirectTarget !== null) {
            return ApiResponse::error(
                code: 'MOVED_PERMANENTLY',
                message: 'This directory location has moved to a new URL.',
                status: Response::HTTP_MOVED_PERMANENTLY,
                details: ['target_path' => $redirectTarget],
                headers: ['Location' => $redirectTarget],
            );
        }

        $result = $this->searchEngine->search($query);

        $items = SearchBusinessHitResource::collection($result->hits)->resolve($request);

        $cacheMaxAge = (int) config('search.cache.search_http_max_age', 30);
        $cacheControl = ($query->openNow || $query->hasCoordinates())
            ? 'private, no-store'
            : "public, max-age={$cacheMaxAge}, stale-while-revalidate=60";

        return ApiResponse::success(
            data: $items,
            meta: [
                'pagination' => $result->pagination,
                'applied_filters' => $result->appliedFilters,
                'counts' => [
                    'total_organic' => $result->totalOrganic,
                    'sponsored_count' => $result->sponsoredCount,
                    'returned_count' => count($items),
                ],
                'query' => [
                    'original' => $result->originalQuery,
                    'normalized' => $result->normalizedQuery,
                ],
                'seo' => $result->seo,
                'performance' => [
                    'duration_ms' => $result->durationMs,
                    'driver' => $result->driver,
                ],
            ],
            status: Response::HTTP_OK,
            headers: [
                'Cache-Control' => $cacheControl,
                'X-Robots-Tag' => (string) ($result->seo['robots'] ?? 'noindex, follow'),
            ],
        );
    }

    public function nearby(NearbySearchRequest $request): JsonResponse
    {
        $query = $request->toSearchQuery(isNearbyMode: true);
        $result = $this->searchEngine->nearby($query);

        $items = SearchBusinessHitResource::collection($result->hits)->resolve($request);

        return ApiResponse::success(
            data: $items,
            meta: [
                'pagination' => $result->pagination,
                'applied_filters' => $result->appliedFilters,
                'counts' => [
                    'total_organic' => $result->totalOrganic,
                    'sponsored_count' => $result->sponsoredCount,
                    'returned_count' => count($items),
                ],
                'query' => [
                    'original' => $result->originalQuery,
                    'normalized' => $result->normalizedQuery,
                ],
                'seo' => $result->seo,
                'performance' => [
                    'duration_ms' => $result->durationMs,
                    'driver' => $result->driver,
                ],
            ],
            status: Response::HTTP_OK,
            headers: [
                'Cache-Control' => 'private, no-store',
                'X-Robots-Tag' => 'noindex, follow',
            ],
        );
    }

    public function suggestions(SearchSuggestionsRequest $request): JsonResponse
    {
        $result = $this->searchEngine->suggestions($request->toSuggestionQuery());
        $maxAge = (int) config('search.cache.suggestions_http_max_age', 60);

        return ApiResponse::success(
            data: [
                'categories' => $result->categories,
                'services' => $result->services,
                'cities' => $result->cities,
                'localities' => $result->localities,
                'businesses' => $result->businesses,
            ],
            meta: [
                'query' => [
                    'original' => $result->originalQuery,
                    'normalized' => $result->normalizedQuery,
                ],
                'cache' => [
                    'hit' => $result->cacheHit,
                    'source' => $result->cacheSource,
                ],
                'performance' => [
                    'duration_ms' => $result->durationMs,
                ],
            ],
            status: Response::HTTP_OK,
            headers: [
                'Cache-Control' => "public, max-age={$maxAge}, stale-while-revalidate=120",
                'X-Robots-Tag' => 'noindex, follow',
            ],
        );
    }

    public function filters(SearchFiltersRequest $request): JsonResponse
    {
        $result = $this->searchEngine->filters($request->toFilterQuery());
        $maxAge = (int) config('search.cache.filters_http_max_age', 120);

        return ApiResponse::success(
            data: [
                'cities' => $result->cities,
                'localities' => $result->localities,
                'categories' => $result->categories,
                'amenities' => $result->amenities,
                'payment_methods' => $result->paymentMethods,
                'rating_options' => $result->ratingOptions,
                'sort_options' => $result->sortOptions,
                'radius' => $result->radius,
                'category_filters' => $result->categoryFilters,
            ],
            meta: [
                'counts' => [
                    'total_active' => $result->totalActiveCount,
                    'verified_active' => $result->verifiedActiveCount,
                ],
            ],
            status: Response::HTTP_OK,
            headers: [
                'Cache-Control' => "public, max-age={$maxAge}, stale-while-revalidate=300",
                'X-Robots-Tag' => 'noindex, follow',
            ],
        );
    }
}
