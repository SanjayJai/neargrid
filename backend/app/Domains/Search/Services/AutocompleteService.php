<?php

declare(strict_types=1);

namespace App\Domains\Search\Services;

use App\Domains\Directory\Enums\BusinessStatus;
use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Models\BusinessService;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Search\DTOs\SuggestionQuery;
use App\Domains\Search\DTOs\SuggestionResult;
use App\Domains\Taxonomy\Models\Category;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AutocompleteService
{
    public function __construct(
        private readonly QueryNormalizerAndSynonymResolver $normalizer,
    ) {}

    public function suggest(SuggestionQuery $query): SuggestionResult
    {
        $startedAt = microtime(true);
        $parsed = $this->normalizer->parse($query->rawQuery);
        $original = $parsed->originalQuery ?? trim($query->rawQuery);
        $normalized = $parsed->normalizedQuery ?? '';

        $minLength = (int) config('search.autocomplete.min_query_length', 2);
        if (mb_strlen($normalized, 'UTF-8') < $minLength) {
            return new SuggestionResult(
                originalQuery: $original,
                normalizedQuery: $normalized,
                categories: [],
                services: [],
                cities: [],
                localities: [],
                businesses: [],
                cacheHit: false,
                cacheSource: 'skipped_min_length',
                durationMs: (int) round((microtime(true) - $startedAt) * 1000),
            );
        }

        $storeName = (string) config('search.autocomplete.cache_store', config('cache.default', 'array'));
        $ttlSeconds = max(10, (int) config('search.autocomplete.cache_ttl_seconds', 120));
        $cacheKey = 'search:suggestions:v1:'.sha1(implode('|', [
            $query->locale,
            $query->citySlug ?? 'all',
            $normalized,
        ]));

        // Attempt cache read with graceful fallback if Redis/cache store is unavailable.
        $cacheAvailable = true;
        try {
            /** @var array<string, mixed>|null $cached */
            $cached = Cache::store($storeName)->get($cacheKey);
            if (is_array($cached)) {
                return new SuggestionResult(
                    originalQuery: $original,
                    normalizedQuery: $normalized,
                    categories: $cached['categories'] ?? [],
                    services: $cached['services'] ?? [],
                    cities: $cached['cities'] ?? [],
                    localities: $cached['localities'] ?? [],
                    businesses: $cached['businesses'] ?? [],
                    cacheHit: true,
                    cacheSource: $storeName,
                    durationMs: (int) round((microtime(true) - $startedAt) * 1000),
                );
            }
        } catch (Throwable $exception) {
            $cacheAvailable = false;
            Log::warning('search.autocomplete_cache_fallback', [
                'store' => $storeName,
                'reason' => $exception->getMessage(),
            ]);
        }

        $payload = $this->queryDatabase($query, $parsed->expandedTerms, $parsed->matchedCategoryIds, $normalized);

        if ($cacheAvailable) {
            try {
                Cache::store($storeName)->put($cacheKey, $payload, $ttlSeconds);
            } catch (Throwable $exception) {
                $cacheAvailable = false;
                Log::warning('search.autocomplete_cache_write_failed', [
                    'store' => $storeName,
                    'reason' => $exception->getMessage(),
                ]);
            }
        }

        return new SuggestionResult(
            originalQuery: $original,
            normalizedQuery: $normalized,
            categories: $payload['categories'],
            services: $payload['services'],
            cities: $payload['cities'],
            localities: $payload['localities'],
            businesses: $payload['businesses'],
            cacheHit: false,
            cacheSource: $cacheAvailable ? $storeName : 'database_fallback',
            durationMs: (int) round((microtime(true) - $startedAt) * 1000),
        );
    }

    /**
     * Query ONLY active, publicly visible entities. Never returns unpublished/suspended businesses or PII.
     *
     * @param  list<string>  $expandedTerms
     * @param  list<int>  $matchedCategoryIds
     * @return array{
     *     categories: list<array<string, mixed>>,
     *     services: list<array<string, mixed>>,
     *     cities: list<array<string, mixed>>,
     *     localities: list<array<string, mixed>>,
     *     businesses: list<array<string, mixed>>
     * }
     */
    private function queryDatabase(
        SuggestionQuery $query,
        array $expandedTerms,
        array $matchedCategoryIds,
        string $normalized,
    ): array {
        $locales = array_values(array_unique([$query->locale, 'en']));
        $limits = (array) config('search.autocomplete.max_per_group', [
            'categories' => 4,
            'services' => 4,
            'localities' => 4,
            'cities' => 3,
            'businesses' => 5,
        ]);

        $escaped = addcslashes($normalized, '\\%_');
        $containsPattern = "%{$escaped}%";
        $prefixPattern = "{$escaped}%";

        $city = null;
        if ($query->citySlug !== null && $query->citySlug !== '') {
            $city = City::query()
                ->where('slug', $query->citySlug)
                ->where('is_active', true)
                ->first();
        }

        // 1. Active Categories (matching name, translations, synonyms, or resolved Tamil/Tanglish synonym IDs)
        $categoriesQuery = Category::query()
            ->where('is_active', true)
            ->with(['translations' => static fn ($q) => $q->whereIn('locale', $locales)])
            ->where(function ($q) use ($containsPattern, $matchedCategoryIds, $expandedTerms): void {
                $q->where('name', 'like', $containsPattern)
                    ->orWhereHas('translations', function ($t) use ($containsPattern, $expandedTerms): void {
                        $t->where('name', 'like', $containsPattern)
                            ->orWhere('synonyms', 'like', $containsPattern);

                        foreach (array_slice($expandedTerms, 0, 5) as $term) {
                            $escTerm = addcslashes($term, '\\%_');
                            $t->orWhere('name', 'like', "%{$escTerm}%")
                                ->orWhere('synonyms', 'like', "%{$escTerm}%");
                        }
                    });

                if ($matchedCategoryIds !== []) {
                    $q->orWhereIn('id', $matchedCategoryIds);
                }
            })
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->limit((int) ($limits['categories'] ?? 4))
            ->get();

        $categories = $categoriesQuery->map(static fn (Category $cat): array => [
            'public_id' => $cat->public_id,
            'slug' => $cat->slug,
            'name' => $cat->translatedName($query->locale),
            'type' => 'category',
        ])->values()->all();

        // 2. Active Services belonging strictly to active, non-deleted businesses
        $servicesQuery = BusinessService::query()
            ->select('business_services.name')
            ->distinct()
            ->where('business_services.is_active', true)
            ->whereHas('business', static function ($b) use ($city): void {
                $b->where('status', BusinessStatus::Active->value)
                    ->whereNull('deleted_at');
                if ($city !== null) {
                    $b->where('city_id', $city->id);
                }
            })
            ->where(function ($q) use ($containsPattern, $expandedTerms): void {
                $q->where('business_services.name', 'like', $containsPattern);
                foreach (array_slice($expandedTerms, 0, 4) as $term) {
                    $escTerm = addcslashes($term, '\\%_');
                    $q->orWhere('business_services.name', 'like', "%{$escTerm}%");
                }
            })
            ->orderBy('business_services.name')
            ->limit((int) ($limits['services'] ?? 4))
            ->pluck('business_services.name');

        $services = $servicesQuery->map(static fn (string $name): array => [
            'name' => $name,
            'type' => 'service',
        ])->values()->all();

        // 3. Active Cities
        $citiesQuery = City::query()
            ->where('is_active', true)
            ->with(['translations' => static fn ($q) => $q->whereIn('locale', $locales)])
            ->where(function ($q) use ($containsPattern): void {
                $q->where('name', 'like', $containsPattern)
                    ->orWhereHas('translations', static fn ($t) => $t->where('name', 'like', $containsPattern));
            })
            ->orderByDesc('is_popular')
            ->orderBy('name')
            ->limit((int) ($limits['cities'] ?? 3))
            ->get();

        $cities = $citiesQuery->map(static fn (City $c): array => [
            'slug' => $c->slug,
            'name' => $c->translatedName($query->locale),
            'type' => 'city',
        ])->values()->all();

        // 4. Active Localities (scoped to selected city when provided)
        $localitiesQuery = Locality::query()
            ->where('is_active', true)
            ->with([
                'city:id,slug,name',
                'translations' => static fn ($q) => $q->whereIn('locale', $locales),
            ])
            ->when($city !== null, static fn ($q) => $q->where('city_id', $city->id))
            ->where(function ($q) use ($containsPattern): void {
                $q->where('name', 'like', $containsPattern)
                    ->orWhere('pincode', 'like', $containsPattern)
                    ->orWhereHas('translations', static fn ($t) => $t->where('name', 'like', $containsPattern));
            })
            ->orderBy('name')
            ->limit((int) ($limits['localities'] ?? 4))
            ->get();

        $localities = $localitiesQuery->map(static fn (Locality $loc): array => [
            'slug' => $loc->slug,
            'name' => $loc->translatedName($query->locale),
            'city_slug' => $loc->city?->slug,
            'pincode' => $loc->pincode,
            'type' => 'locality',
        ])->values()->all();

        // 5. Active Published Businesses ONLY (zero private/unpublished/suspended leakage)
        $businessesQuery = Business::query()
            ->published()
            ->when($city !== null, static fn ($q) => $q->where('city_id', $city->id))
            ->where(function ($q) use ($prefixPattern, $containsPattern, $expandedTerms): void {
                $q->where('name', 'like', $prefixPattern)
                    ->orWhere('name', 'like', $containsPattern)
                    ->orWhere('search_keywords', 'like', $containsPattern);

                foreach (array_slice($expandedTerms, 0, 3) as $term) {
                    $escTerm = addcslashes($term, '\\%_');
                    $q->orWhere('name', 'like', "%{$escTerm}%");
                }
            })
            ->with([
                'city:id,slug,name',
                'locality.translations' => static fn ($q) => $q->whereIn('locale', $locales),
            ])
            ->orderByDesc('is_verified')
            ->orderByDesc('bayesian_score')
            ->orderBy('name')
            ->limit((int) ($limits['businesses'] ?? 5))
            ->get();

        $businesses = $businessesQuery->map(static fn (Business $biz): array => [
            'public_id' => $biz->public_id,
            'name' => $biz->name,
            'slug' => $biz->slug,
            'city_slug' => $biz->city?->slug,
            'locality_name' => $biz->locality?->translatedName($query->locale),
            'is_verified' => $biz->is_verified,
            'type' => 'business',
        ])->values()->all();

        return [
            'categories' => $categories,
            'services' => $services,
            'cities' => $cities,
            'localities' => $localities,
            'businesses' => $businesses,
        ];
    }
}
