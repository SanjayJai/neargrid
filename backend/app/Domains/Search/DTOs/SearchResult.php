<?php

declare(strict_types=1);

namespace App\Domains\Search\DTOs;

final readonly class SearchResult
{
    /**
     * @param  list<SearchHit>  $hits
     * @param  array<string, mixed>  $pagination
     * @param  array<string, mixed>  $appliedFilters
     * @param  array<string, mixed>  $seo
     */
    public function __construct(
        public array $hits,
        public array $pagination,
        public array $appliedFilters,
        public array $seo,
        public int $totalOrganic,
        public int $sponsoredCount,
        public int $durationMs,
        public string $driver,
        public ?string $originalQuery,
        public ?string $normalizedQuery,
    ) {}
}
