<?php

declare(strict_types=1);

namespace App\Domains\Search\Services;

use App\Domains\Directory\Models\Business;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Search\DTOs\FilterOptionsResult;
use App\Domains\Search\DTOs\FilterQuery;
use App\Domains\Search\Enums\SearchSortOption;
use App\Domains\Taxonomy\Models\Amenity;
use App\Domains\Taxonomy\Models\Category;
use App\Domains\Taxonomy\Models\PaymentMethod;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class FilterOptionsService
{
    public function getFilters(FilterQuery $query): FilterOptionsResult
    {
        $storeName = (string) config('search.autocomplete.cache_store', config('cache.default', 'array'));
        $ttl = max(15, (int) config('search.cache.filters_ttl_seconds', 120));
        $cacheKey = 'search:filters:v1:'.sha1(implode('|', [
            $query->locale,
            $query->citySlug ?? 'all',
            $query->categorySlug ?? 'all',
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

        $categoryIds = [];
        if ($query->categorySlug !== null && $query->categorySlug !== '') {
            $cat = Category::query()
                ->where('slug', $query->categorySlug)
                ->where('is_active', true)
                ->first();
            if ($cat !== null) {
                $childIds = Category::query()
                    ->where('parent_id', $cat->id)
                    ->where('is_active', true)
                    ->pluck('id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all();
                $categoryIds = [$cat->id, ...$childIds];
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
        ];
    }
}
