<?php

declare(strict_types=1);

namespace App\Domains\Search\Engines;

use App\Domains\Directory\Models\Business;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Search\Contracts\SearchEngineInterface;
use App\Domains\Search\DTOs\FilterOptionsResult;
use App\Domains\Search\DTOs\FilterQuery;
use App\Domains\Search\DTOs\ParsedQuery;
use App\Domains\Search\DTOs\SearchHit;
use App\Domains\Search\DTOs\SearchQuery;
use App\Domains\Search\DTOs\SearchResult;
use App\Domains\Search\DTOs\SuggestionQuery;
use App\Domains\Search\DTOs\SuggestionResult;
use App\Domains\Search\Enums\SearchSortOption;
use App\Domains\Search\Services\AutocompleteService;
use App\Domains\Search\Services\FilterOptionsService;
use App\Domains\Search\Services\GeoSpatialHelper;
use App\Domains\Search\Services\QueryNormalizerAndSynonymResolver;
use App\Domains\Search\Services\SearchPerformanceLogger;
use App\Domains\Search\Services\SearchRankingService;
use App\Domains\Search\Services\SearchSeoAdvisor;
use App\Domains\Search\Services\SponsoredPlacementSelector;
use App\Domains\Taxonomy\Models\Amenity;
use App\Domains\Taxonomy\Models\Category;
use App\Domains\Taxonomy\Models\CategoryAttributeDefinition;
use App\Domains\Taxonomy\Models\CategoryFilterConfig;
use App\Domains\Taxonomy\Models\PaymentMethod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Stage 1 MySQL 8 Native Search implementation (FULLTEXT + SRID 4326 Spatial + B-Tree)
 * with safe parameterized SQLite compatibility for local unit/feature tests.
 */
final class MysqlSearchEngine implements SearchEngineInterface
{
    public function __construct(
        private readonly QueryNormalizerAndSynonymResolver $normalizer,
        private readonly GeoSpatialHelper $geoHelper,
        private readonly SearchRankingService $rankingService,
        private readonly SponsoredPlacementSelector $sponsoredSelector,
        private readonly SearchSeoAdvisor $seoAdvisor,
        private readonly SearchPerformanceLogger $performanceLogger,
        private readonly AutocompleteService $autocompleteService,
        private readonly FilterOptionsService $filterOptionsService,
    ) {}

    public function driverName(): string
    {
        return 'mysql';
    }

    public function search(SearchQuery $query): SearchResult
    {
        return $this->executeSearch($query);
    }

    public function nearby(SearchQuery $query): SearchResult
    {
        $nearbyQuery = new SearchQuery(
            rawQuery: $query->rawQuery,
            citySlug: $query->citySlug,
            categorySlug: $query->categorySlug,
            localitySlugs: $query->localitySlugs,
            latitude: $query->latitude,
            longitude: $query->longitude,
            radiusKm: $query->radiusKm ?? (float) config('search.geo.default_radius_km', 10.0),
            minRating: $query->minRating,
            verifiedOnly: $query->verifiedOnly,
            openNow: $query->openNow,
            amenitySlugs: $query->amenitySlugs,
            paymentMethodSlugs: $query->paymentMethodSlugs,
            sort: $query->sort === SearchSortOption::Relevance ? SearchSortOption::Distance : $query->sort,
            page: $query->page,
            perPage: $query->perPage,
            locale: $query->locale,
            isNearbyMode: true,
            attributeFilters: $query->attributeFilters,
        );

        return $this->executeSearch($nearbyQuery);
    }

    public function suggestions(SuggestionQuery $query): SuggestionResult
    {
        return $this->autocompleteService->suggest($query);
    }

    public function filters(FilterQuery $query): FilterOptionsResult
    {
        return $this->filterOptionsService->getFilters($query);
    }

    private function executeSearch(SearchQuery $query): SearchResult
    {
        $startedAt = microtime(true);
        $locales = array_values(array_unique([$query->locale, 'en']));
        $parsed = $this->normalizer->parse($query->rawQuery);

        // 1. Resolve validated slugs to safe internal IDs (fail closed to empty results on unknown explicit slugs)
        $city = null;
        if ($query->citySlug !== null && $query->citySlug !== '') {
            $city = City::query()
                ->where('slug', $query->citySlug)
                ->where('is_active', true)
                ->first();

            if ($city === null) {
                return $this->emptyResult($query, $parsed, $startedAt);
            }
        }

        $explicitCategoryIds = [];
        $resolvedCategory = null;
        if ($query->categorySlug !== null && $query->categorySlug !== '') {
            $category = Category::query()
                ->where('slug', $query->categorySlug)
                ->where('is_active', true)
                ->first();

            if ($category === null) {
                return $this->emptyResult($query, $parsed, $startedAt);
            }

            $resolvedCategory = $category;
            $childIds = Category::query()
                ->where('parent_id', $category->id)
                ->where('is_active', true)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            $explicitCategoryIds = [(int) $category->id, ...$childIds];
        }

        $localityModels = collect();
        $localityIds = [];
        if ($query->localitySlugs !== []) {
            $locQ = Locality::query()
                ->whereIn('slug', $query->localitySlugs)
                ->where('is_active', true);
            if ($city !== null) {
                $locQ->where('city_id', $city->id);
            }
            $localityModels = $locQ->get();
            $localityIds = $localityModels->pluck('id')->map(static fn ($id): int => (int) $id)->all();

            if ($localityIds === []) {
                return $this->emptyResult($query, $parsed, $startedAt);
            }
        }

        $amenityIds = [];
        if ($query->amenitySlugs !== []) {
            $amenityIds = Amenity::query()
                ->whereIn('slug', $query->amenitySlugs)
                ->where('is_active', true)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            if (count($amenityIds) !== count(array_unique($query->amenitySlugs))) {
                return $this->emptyResult($query, $parsed, $startedAt);
            }
        }

        $paymentMethodIds = [];
        if ($query->paymentMethodSlugs !== []) {
            $paymentMethodIds = PaymentMethod::query()
                ->whereIn('slug', $query->paymentMethodSlugs)
                ->where('is_active', true)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            if (count($paymentMethodIds) !== count(array_unique($query->paymentMethodSlugs))) {
                return $this->emptyResult($query, $parsed, $startedAt);
            }
        }

        // 2. Resolve geo anchor (explicit coordinates > single locality centroid > city centroid)
        $anchorLat = $query->latitude;
        $anchorLng = $query->longitude;
        $enforceRadius = $query->hasCoordinates();
        $effectiveRadiusKm = $enforceRadius
            ? ($query->radiusKm ?? (float) config('search.geo.default_radius_km', 10.0))
            : null;

        if (! $query->hasCoordinates()) {
            if ($localityModels->count() === 1) {
                $loc = $localityModels->first();
                if ($loc->latitude !== null && $loc->longitude !== null) {
                    $anchorLat = (float) $loc->latitude;
                    $anchorLng = (float) $loc->longitude;
                }
            } elseif ($city !== null && $city->latitude !== null && $city->longitude !== null) {
                $anchorLat = (float) $city->latitude;
                $anchorLng = (float) $city->longitude;
            }
        }

        $isMysql = DB::getDriverName() === 'mysql';
        $timezone = $city?->timezone ?? (string) config('directory.default_timezone', 'Asia/Kolkata');

        // 3. Build strictly parameterized organic query (active & non-deleted businesses ONLY)
        $builder = Business::query()
            ->published()
            ->select('businesses.*');

        if ($city !== null) {
            $builder->where('businesses.city_id', $city->id);
        }

        if ($explicitCategoryIds !== []) {
            $builder->where(static function (Builder $q) use ($explicitCategoryIds): void {
                $q->whereIn('businesses.primary_category_id', $explicitCategoryIds)
                    ->orWhereHas('categories', static fn (Builder $c) => $c->whereIn('categories.id', $explicitCategoryIds));
            });
        }

        if ($localityIds !== []) {
            $builder->whereIn('businesses.locality_id', $localityIds);
        }

        if ($query->verifiedOnly) {
            $builder->where('businesses.is_verified', true);
        }

        if ($query->minRating !== null) {
            $builder->where('businesses.rating_avg', '>=', $query->minRating);
        }

        foreach ($amenityIds as $amenityId) {
            $builder->whereHas('amenities', static fn (Builder $a) => $a->where('amenities.id', $amenityId));
        }

        foreach ($paymentMethodIds as $pmId) {
            $builder->whereHas('paymentMethods', static fn (Builder $pm) => $pm->where('payment_methods.id', $pmId));
        }

        if ($query->openNow) {
            $this->applyOpenNowFilter($builder, $timezone);
        }

        if ($query->attributeFilters !== []) {
            if ($resolvedCategory === null) {
                return $this->emptyResult($query, $parsed, $startedAt);
            }
            $this->applyCategoryAttributeFilters($builder, $resolvedCategory, $query->attributeFilters, $isMysql);
        }

        // 4. Spatial bounding-box & exact distance filter
        if ($enforceRadius && $anchorLat !== null && $anchorLng !== null && $effectiveRadiusKm !== null) {
            if ($isMysql) {
                $polygonWkt = $this->geoHelper->boundingPolygonWkt($anchorLat, $anchorLng, $effectiveRadiusKm);
                $pointWkt = $this->geoHelper->pointWkt($anchorLat, $anchorLng);

                $builder->selectRaw(
                    'ST_Distance_Sphere(businesses.location_point, ST_GeomFromText(?, 4326)) / 1000.0 AS computed_distance_km',
                    [$pointWkt]
                );
                $builder->whereRaw(
                    'MBRContains(ST_GeomFromText(?, 4326), businesses.location_point)',
                    [$polygonWkt]
                );
                $builder->whereRaw(
                    'ST_Distance_Sphere(businesses.location_point, ST_GeomFromText(?, 4326)) / 1000.0 <= ?',
                    [$pointWkt, $effectiveRadiusKm]
                );
            } else {
                $box = $this->geoHelper->boundingBox($anchorLat, $anchorLng, $effectiveRadiusKm);
                $builder->whereBetween('businesses.latitude', [$box['min_lat'], $box['max_lat']])
                    ->whereBetween('businesses.longitude', [$box['min_lng'], $box['max_lng']]);
            }
        } elseif ($isMysql && $anchorLat !== null && $anchorLng !== null) {
            $pointWkt = $this->geoHelper->pointWkt($anchorLat, $anchorLng);
            $builder->selectRaw(
                'ST_Distance_Sphere(businesses.location_point, ST_GeomFromText(?, 4326)) / 1000.0 AS computed_distance_km',
                [$pointWkt]
            );
        }

        // 5. Text, Category Synonym & Service matching (MySQL 8 FULLTEXT + parameterized multilingual fallback)
        if ($parsed->hasTextQuery()) {
            $this->applyTextAndSynonymFilter($builder, $parsed, $isMysql);
        }

        // Eager-load relations needed for scoring without N+1 queries
        $candidates = $builder
            ->with([
                'categories:id',
                'services' => static fn ($q) => $q->where('is_active', true),
            ])
            ->get();

        // Compute exact distance & filter radius on non-MySQL engines, plus compute max batch FullText score
        $maxFtScore = 0.0;
        foreach ($candidates as $candidate) {
            $ft = (float) ($candidate->getAttribute('ft_score') ?? 0.0);
            if ($ft > $maxFtScore) {
                $maxFtScore = $ft;
            }
        }
        if ($maxFtScore <= 0.0) {
            $maxFtScore = 1.0;
        }

        $targetCategoryIds = array_values(array_unique([...$explicitCategoryIds, ...$parsed->matchedCategoryIds]));

        /** @var list<SearchHit> $organicHits */
        $organicHits = [];
        foreach ($candidates as $business) {
            $distanceKm = null;
            if ($anchorLat !== null && $anchorLng !== null) {
                $sqlDistance = $business->getAttribute('computed_distance_km');
                $distanceKm = $sqlDistance !== null
                    ? round((float) $sqlDistance, 3)
                    : $this->geoHelper->distanceKm(
                        $anchorLat,
                        $anchorLng,
                        (float) $business->latitude,
                        (float) $business->longitude,
                    );

                if ($enforceRadius && $effectiveRadiusKm !== null && $distanceKm > $effectiveRadiusKm) {
                    continue;
                }
            }

            $score = $this->rankingService->scoreOrganic(
                business: $business,
                parsedQuery: $parsed,
                targetCategoryIds: $targetCategoryIds,
                distanceKm: $distanceKm,
                mysqlFullTextScore: (float) ($business->getAttribute('ft_score') ?? 0.0),
                maxBatchFullTextScore: $maxFtScore,
            );

            $organicHits[] = new SearchHit(
                business: $business,
                distanceKm: $distanceKm,
                isSponsored: false,
                sponsorDisclosure: null,
                relevanceScore: $score,
            );
        }

        // 6. Sort organic hits deterministically
        $organicHits = $this->rankingService->sortHits($organicHits, $query->sort);
        $totalOrganic = count($organicHits);

        // 7. Select strictly separated Sponsored placements for Page 1 (passing active & quality gates)
        $sponsoredHits = $this->sponsoredSelector->selectForPage(
            query: $query,
            cityId: $city?->id,
            targetCategoryIds: $targetCategoryIds,
            localityIds: $localityIds,
            amenityIds: $amenityIds,
            paymentMethodIds: $paymentMethodIds,
            anchorLat: $anchorLat,
            anchorLng: $anchorLng,
            effectiveRadiusKm: $effectiveRadiusKm,
            locales: $locales,
        );

        $sponsoredBusinessIds = array_map(
            static fn (SearchHit $hit): int => (int) $hit->business->id,
            $sponsoredHits
        );

        // Slice organic results for current page (deduplicating sponsored IDs on Page 1)
        $offset = ($query->page - 1) * $query->perPage;
        if ($query->page === 1 && $sponsoredBusinessIds !== []) {
            $dedupedOrganic = array_values(array_filter(
                $organicHits,
                static fn (SearchHit $hit): bool => ! in_array((int) $hit->business->id, $sponsoredBusinessIds, true)
            ));
            $organicPageHits = array_slice($dedupedOrganic, 0, $query->perPage);
        } else {
            $organicPageHits = array_slice($organicHits, $offset, $query->perPage);
        }

        // 8. Batch eager-load presentation relations ONLY for the final page IDs (zero N+1!)
        $organicPageIds = array_map(static fn (SearchHit $h): int => (int) $h->business->id, $organicPageHits);
        if ($organicPageIds !== []) {
            $hydrated = Business::query()
                ->whereIn('id', $organicPageIds)
                ->with([
                    'city.translations' => static fn ($q) => $q->whereIn('locale', $locales),
                    'locality.translations' => static fn ($q) => $q->whereIn('locale', $locales),
                    'primaryCategory.translations' => static fn ($q) => $q->whereIn('locale', $locales),
                    'hours',
                    'services' => static fn ($q) => $q->where('is_active', true),
                    'media' => static fn ($q) => $q->publicApproved(),
                ])
                ->get()
                ->keyBy('id');

            $organicPageHits = array_values(array_map(
                static fn (SearchHit $hit): SearchHit => new SearchHit(
                    business: $hydrated->get($hit->business->id) ?? $hit->business,
                    distanceKm: $hit->distanceKm,
                    isSponsored: false,
                    sponsorDisclosure: null,
                    relevanceScore: $hit->relevanceScore,
                ),
                $organicPageHits
            ));
        }

        $finalHits = [...$sponsoredHits, ...$organicPageHits];
        $lastPage = max(1, (int) ceil($totalOrganic / max(1, $query->perPage)));
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        $seo = $this->seoAdvisor->evaluate($query, $totalOrganic);
        $this->performanceLogger->record($query, $parsed, $totalOrganic, $durationMs, $this->driverName());

        return new SearchResult(
            hits: $finalHits,
            pagination: [
                'type' => 'offset',
                'current_page' => $query->page,
                'per_page' => $query->perPage,
                'total' => $totalOrganic,
                'last_page' => $lastPage,
                'has_more' => $query->page < $lastPage,
                'sponsored_count' => count($sponsoredHits),
                'returned_count' => count($finalHits),
            ],
            appliedFilters: $this->formatAppliedFilters($query, $parsed, $effectiveRadiusKm),
            seo: $seo,
            totalOrganic: $totalOrganic,
            sponsoredCount: count($sponsoredHits),
            durationMs: $durationMs,
            driver: $this->driverName(),
            originalQuery: $parsed->originalQuery,
            normalizedQuery: $parsed->normalizedQuery,
        );
    }

    /**
     * @param  Builder<Business>  $builder
     */
    private function applyTextAndSynonymFilter(Builder $builder, ParsedQuery $parsed, bool $isMysql): void
    {
        $terms = array_values(array_unique(array_slice(
            $parsed->expandedTerms !== [] ? $parsed->expandedTerms : $parsed->tokens,
            0,
            12
        )));

        $ftString = implode(' ', $terms);

        if ($isMysql && $ftString !== '') {
            $builder->selectRaw(
                'MATCH(businesses.name, businesses.tagline, businesses.description, businesses.search_keywords) AGAINST (? IN NATURAL LANGUAGE MODE) AS ft_score',
                [$ftString]
            );
        }

        $builder->where(function (Builder $q) use ($parsed, $terms, $ftString, $isMysql): void {
            if ($isMysql && $ftString !== '') {
                $q->whereRaw(
                    'MATCH(businesses.name, businesses.tagline, businesses.description, businesses.search_keywords) AGAINST (? IN NATURAL LANGUAGE MODE)',
                    [$ftString]
                )->orWhereExists(static function ($sub) use ($ftString): void {
                    $sub->selectRaw('1')
                        ->from('business_services')
                        ->whereColumn('business_services.business_id', 'businesses.id')
                        ->where('business_services.is_active', true)
                        ->whereRaw('MATCH(business_services.name, business_services.description) AGAINST (? IN NATURAL LANGUAGE MODE)', [$ftString]);
                });
            }

            if ($parsed->matchedCategoryIds !== []) {
                $q->orWhereIn('businesses.primary_category_id', $parsed->matchedCategoryIds)
                    ->orWhereHas('categories', static fn (Builder $c) => $c->whereIn('categories.id', $parsed->matchedCategoryIds));
            }

            // Parameterized LIKE matching for short tokens (e.g. AC), Tamil inflection stems, and SQLite tests
            foreach ($terms as $term) {
                $escaped = addcslashes($term, '\\%_');
                $like = "%{$escaped}%";

                $q->orWhere('businesses.name', 'like', $like)
                    ->orWhere('businesses.tagline', 'like', $like)
                    ->orWhere('businesses.description', 'like', $like)
                    ->orWhere('businesses.search_keywords', 'like', $like)
                    ->orWhereHas('services', static function (Builder $s) use ($like): void {
                        $s->where('is_active', true)
                            ->where(static function (Builder $inner) use ($like): void {
                                $inner->where('name', 'like', $like)
                                    ->orWhere('description', 'like', $like);
                            });
                    })
                    ->orWhereHas('primaryCategory.translations', static function (Builder $ct) use ($like): void {
                        $ct->where('name', 'like', $like)
                            ->orWhere('synonyms', 'like', $like);
                    });
            }
        });
    }

    /**
     * Apply timezone-aware split-shift & midnight-crossing Open Now SQL filter.
     *
     * @param  Builder<Business>  $builder
     */
    private function applyOpenNowFilter(Builder $builder, string $timezone): void
    {
        $now = CarbonImmutable::now()->setTimezone($timezone);
        $today = (int) $now->dayOfWeek;
        $yesterday = ($today + 6) % 7;
        $currentTime = $now->format('H:i:s');

        $builder->whereHas('hours', static function (Builder $h) use ($today, $yesterday, $currentTime): void {
            $h->where('is_closed', false)
                ->where(static function (Builder $slots) use ($today, $yesterday, $currentTime): void {
                    // 24-hour slot today
                    $slots->where(static function (Builder $s) use ($today): void {
                        $s->where('day_of_week', $today)->where('is_24_hours', true);
                    })
                        // Normal same-day window today
                        ->orWhere(static function (Builder $s) use ($today, $currentTime): void {
                            $s->where('day_of_week', $today)
                                ->whereNotNull('open_time')
                                ->whereNotNull('close_time')
                                ->whereColumn('open_time', '<=', 'close_time')
                                ->where('open_time', '<=', $currentTime)
                                ->where('close_time', '>=', $currentTime);
                        })
                        // Midnight-crossing shift started today
                        ->orWhere(static function (Builder $s) use ($today, $currentTime): void {
                            $s->where('day_of_week', $today)
                                ->whereNotNull('open_time')
                                ->whereNotNull('close_time')
                                ->whereColumn('open_time', '>', 'close_time')
                                ->where('open_time', '<=', $currentTime);
                        })
                        // Midnight-crossing shift started yesterday
                        ->orWhere(static function (Builder $s) use ($yesterday, $currentTime): void {
                            $s->where('day_of_week', $yesterday)
                                ->whereNotNull('open_time')
                                ->whereNotNull('close_time')
                                ->whereColumn('open_time', '>', 'close_time')
                                ->where('close_time', '>=', $currentTime);
                        });
                });
        });
    }

    /**
     * Apply strictly allowlisted, parameter-bound WHERE EXISTS predicates for category attributes.
     *
     * @param  Builder<Business>  $builder
     * @param  array<string, mixed>  $attributeFilters
     */
    private function applyCategoryAttributeFilters(
        Builder $builder,
        Category $category,
        array $attributeFilters,
        bool $isMysql,
    ): void {
        $bounded = array_slice($attributeFilters, 0, 8, true);
        if ($bounded === []) {
            return;
        }

        $definitions = CategoryAttributeDefinition::query()
            ->where('category_id', $category->id)
            ->where('is_active', true)
            ->where('is_filterable', true)
            ->whereIn('attribute_key', array_keys($bounded))
            ->get()
            ->keyBy('attribute_key');

        $disabledFilterKeys = CategoryFilterConfig::query()
            ->where('category_id', $category->id)
            ->where('is_enabled', false)
            ->pluck('filter_key')
            ->flip();

        foreach ($bounded as $key => $spec) {
            /** @var CategoryAttributeDefinition|null $def */
            $def = $definitions->get($key);
            if ($def === null || $disabledFilterKeys->has($key) || ! is_array($spec)) {
                // Fail closed if an unallowlisted filter reaches the search engine directly.
                $builder->whereRaw('1 = 0');
                return;
            }

            $builder->whereExists(static function ($sub) use ($def, $spec, $isMysql): void {
                $sub->selectRaw('1')
                    ->from('business_attribute_values as bav')
                    ->whereColumn('bav.business_id', 'businesses.id')
                    ->where('bav.attribute_definition_id', $def->id);

                switch ($def->data_type) {
                    case 'boolean':
                        $sub->where('bav.value_boolean', (bool) ($spec['value'] ?? false));
                        break;

                    case 'enum':
                    case 'string':
                        $sub->where('bav.value_string', (string) ($spec['value'] ?? ''));
                        break;

                    case 'number':
                        $min = $spec['min'] ?? null;
                        $max = $spec['max'] ?? null;
                        if ($min !== null) {
                            $sub->where('bav.value_number', '>=', (float) $min);
                        }
                        if ($max !== null) {
                            $sub->where('bav.value_number', '<=', (float) $max);
                        }
                        break;

                    case 'multi_enum':
                        /** @var list<string> $values */
                        $values = is_array($spec['values'] ?? null) ? array_values($spec['values']) : [];
                        if ($values === []) {
                            $sub->whereRaw('1 = 0');
                            break;
                        }

                        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                            $sub->where(static function ($jsonQ) use ($values): void {
                                foreach ($values as $val) {
                                    $jsonQ->orWhereRaw(
                                        "JSON_CONTAINS(bav.value_json, ?, '$')",
                                        [json_encode((string) $val, JSON_THROW_ON_ERROR)]
                                    );
                                }
                            });
                        } else {
                            $placeholders = implode(',', array_fill(0, count($values), '?'));
                            $sub->whereRaw(
                                "EXISTS (SELECT 1 FROM json_each(bav.value_json) AS je WHERE je.value IN ({$placeholders}))",
                                array_map('strval', $values)
                            );
                        }
                        break;
                }
            });
        }
    }

    private function emptyResult(SearchQuery $query, ParsedQuery $parsed, float $startedAt): SearchResult
    {
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $seo = $this->seoAdvisor->evaluate($query, 0);
        $this->performanceLogger->record($query, $parsed, 0, $durationMs, $this->driverName());

        return new SearchResult(
            hits: [],
            pagination: [
                'type' => 'offset',
                'current_page' => $query->page,
                'per_page' => $query->perPage,
                'total' => 0,
                'last_page' => 1,
                'has_more' => false,
                'sponsored_count' => 0,
                'returned_count' => 0,
            ],
            appliedFilters: $this->formatAppliedFilters($query, $parsed, $query->radiusKm),
            seo: $seo,
            totalOrganic: 0,
            sponsoredCount: 0,
            durationMs: $durationMs,
            driver: $this->driverName(),
            originalQuery: $parsed->originalQuery,
            normalizedQuery: $parsed->normalizedQuery,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function formatAppliedFilters(SearchQuery $query, ParsedQuery $parsed, ?float $effectiveRadiusKm): array
    {
        return [
            'q' => $parsed->originalQuery,
            'city' => $query->citySlug,
            'category' => $query->categorySlug,
            'localities' => $query->localitySlugs,
            'radius_km' => $effectiveRadiusKm,
            'has_coordinates' => $query->hasCoordinates(),
            'min_rating' => $query->minRating,
            'verified_only' => $query->verifiedOnly,
            'open_now' => $query->openNow,
            'amenities' => $query->amenitySlugs,
            'payment_methods' => $query->paymentMethodSlugs,
            'attributes' => $query->attributeFilters,
            'sort' => $query->sort->value,
            'locale' => $query->locale,
        ];
    }
}
