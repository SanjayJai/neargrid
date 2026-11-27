<?php

declare(strict_types=1);

namespace App\Domains\Search\DTOs;

final readonly class FilterOptionsResult
{
    /**
     * @param  list<array<string, mixed>>  $cities
     * @param  list<array<string, mixed>>  $localities
     * @param  list<array<string, mixed>>  $categories
     * @param  list<array<string, mixed>>  $amenities
     * @param  list<array<string, mixed>>  $paymentMethods
     * @param  list<array<string, mixed>>  $ratingOptions
     * @param  list<array<string, mixed>>  $sortOptions
     * @param  array{min_km: float, max_km: float, default_km: float}  $radius
     * @param  list<array<string, mixed>>  $categoryFilters
     */
    public function __construct(
        public array $cities,
        public array $localities,
        public array $categories,
        public array $amenities,
        public array $paymentMethods,
        public array $ratingOptions,
        public array $sortOptions,
        public array $radius,
        public int $verifiedActiveCount,
        public int $totalActiveCount,
        public array $categoryFilters = [],
    ) {}
}
