<?php

declare(strict_types=1);

namespace App\Domains\Search\DTOs;

use App\Domains\Search\Enums\SearchSortOption;

final readonly class SearchQuery
{
    /**
     * @param  list<string>  $localitySlugs
     * @param  list<string>  $amenitySlugs
     * @param  list<string>  $paymentMethodSlugs
     * @param  array<string, mixed>  $attributeFilters
     */
    public function __construct(
        public ?string $rawQuery = null,
        public ?string $citySlug = null,
        public ?string $categorySlug = null,
        public array $localitySlugs = [],
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?float $radiusKm = null,
        public ?float $minRating = null,
        public bool $verifiedOnly = false,
        public bool $openNow = false,
        public array $amenitySlugs = [],
        public array $paymentMethodSlugs = [],
        public SearchSortOption $sort = SearchSortOption::Relevance,
        public int $page = 1,
        public int $perPage = 20,
        public string $locale = 'en',
        public bool $isNearbyMode = false,
        public array $attributeFilters = [],
    ) {}

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }
}
