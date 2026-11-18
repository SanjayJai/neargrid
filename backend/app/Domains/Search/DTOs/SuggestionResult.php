<?php

declare(strict_types=1);

namespace App\Domains\Search\DTOs;

final readonly class SuggestionResult
{
    /**
     * @param  list<array<string, mixed>>  $categories
     * @param  list<array<string, mixed>>  $services
     * @param  list<array<string, mixed>>  $cities
     * @param  list<array<string, mixed>>  $localities
     * @param  list<array<string, mixed>>  $businesses
     */
    public function __construct(
        public string $originalQuery,
        public string $normalizedQuery,
        public array $categories,
        public array $services,
        public array $cities,
        public array $localities,
        public array $businesses,
        public bool $cacheHit,
        public string $cacheSource,
        public int $durationMs,
    ) {}
}
