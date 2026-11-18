<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Search;

use App\Domains\Search\DTOs\SearchQuery;
use App\Domains\Search\Enums\SearchSortOption;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchBusinessesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('verified_only')) {
            $merge['verified_only'] = filter_var($this->input('verified_only'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        if ($this->has('open_now')) {
            $merge['open_now'] = filter_var($this->input('open_now'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * Strict typed validation — rejects raw SQL, arbitrary sorting expressions, and invalid WGS-84 coordinates.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $minRadius = (float) config('search.geo.min_radius_km', 0.5);
        $maxRadius = (float) config('search.geo.max_radius_km', 50.0);
        $maxPerPage = (int) config('search.pagination.max_per_page', 40);
        $maxPage = (int) config('search.pagination.max_page', 250);

        return [
            'q' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/'],
            'category' => ['nullable', 'string', 'max:140', 'regex:/^[a-z0-9-]+$/'],
            'locality' => ['nullable', 'string', 'max:140', 'regex:/^[a-z0-9-]+$/'],
            'localities' => ['nullable', 'array', 'max:10'],
            'localities.*' => ['string', 'max:140', 'regex:/^[a-z0-9-]+$/'],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'radius_km' => ['nullable', 'numeric', "min:{$minRadius}", "max:{$maxRadius}"],
            'min_rating' => ['nullable', 'numeric', 'between:1,5'],
            'verified_only' => ['nullable', 'boolean'],
            'open_now' => ['nullable', 'boolean'],
            'amenities' => ['nullable', 'array', 'max:15'],
            'amenities.*' => ['string', 'max:100', 'regex:/^[a-z0-9-]+$/'],
            'payment_methods' => ['nullable', 'array', 'max:10'],
            'payment_methods.*' => ['string', 'max:100', 'regex:/^[a-z0-9-]+$/'],
            'sort' => ['nullable', 'string', Rule::in(SearchSortOption::values())],
            'page' => ['nullable', 'integer', 'min:1', "max:{$maxPage}"],
            'per_page' => ['nullable', 'integer', 'min:1', "max:{$maxPerPage}"],
            'locale' => ['nullable', 'string', Rule::in((array) config('directory.supported_locales', ['en', 'ta']))],
        ];
    }

    public function toSearchQuery(bool $isNearbyMode = false): SearchQuery
    {
        $v = $this->validated();

        $localities = [];
        if (isset($v['locality']) && is_string($v['locality']) && $v['locality'] !== '') {
            $localities[] = $v['locality'];
        }
        if (isset($v['localities']) && is_array($v['localities'])) {
            foreach ($v['localities'] as $slug) {
                if (is_string($slug) && $slug !== '') {
                    $localities[] = $slug;
                }
            }
        }
        $localities = array_values(array_unique($localities));

        $amenities = isset($v['amenities']) && is_array($v['amenities'])
            ? array_values(array_unique(array_map('strval', $v['amenities'])))
            : [];

        $paymentMethods = isset($v['payment_methods']) && is_array($v['payment_methods'])
            ? array_values(array_unique(array_map('strval', $v['payment_methods'])))
            : [];

        $defaultPerPage = (int) config('search.pagination.default_per_page', 20);

        return new SearchQuery(
            rawQuery: isset($v['q']) ? (string) $v['q'] : null,
            citySlug: isset($v['city']) ? (string) $v['city'] : null,
            categorySlug: isset($v['category']) ? (string) $v['category'] : null,
            localitySlugs: $localities,
            latitude: isset($v['lat']) ? (float) $v['lat'] : null,
            longitude: isset($v['lng']) ? (float) $v['lng'] : null,
            radiusKm: isset($v['radius_km']) ? (float) $v['radius_km'] : null,
            minRating: isset($v['min_rating']) ? (float) $v['min_rating'] : null,
            verifiedOnly: (bool) ($v['verified_only'] ?? false),
            openNow: (bool) ($v['open_now'] ?? false),
            amenitySlugs: $amenities,
            paymentMethodSlugs: $paymentMethods,
            sort: isset($v['sort']) ? SearchSortOption::from((string) $v['sort']) : SearchSortOption::Relevance,
            page: isset($v['page']) ? (int) $v['page'] : 1,
            perPage: isset($v['per_page']) ? (int) $v['per_page'] : $defaultPerPage,
            locale: isset($v['locale']) ? (string) $v['locale'] : (string) config('directory.default_locale', 'en'),
            isNearbyMode: $isNearbyMode,
        );
    }
}
