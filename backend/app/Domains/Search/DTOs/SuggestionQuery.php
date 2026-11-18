<?php

declare(strict_types=1);

namespace App\Domains\Search\DTOs;

final readonly class SuggestionQuery
{
    public function __construct(
        public string $rawQuery,
        public ?string $citySlug = null,
        public string $locale = 'en',
    ) {}
}
