<?php

declare(strict_types=1);

namespace App\Domains\Search\DTOs;

final readonly class FilterQuery
{
    public function __construct(
        public ?string $citySlug = null,
        public ?string $categorySlug = null,
        public string $locale = 'en',
    ) {}
}
