<?php

declare(strict_types=1);

namespace App\Domains\Search\DTOs;

final readonly class ParsedQuery
{
    /**
     * @param  list<string>  $tokens
     * @param  list<string>  $expandedTerms
     * @param  list<int>  $matchedCategoryIds
     */
    public function __construct(
        public ?string $originalQuery,
        public ?string $normalizedQuery,
        public array $tokens,
        public array $expandedTerms,
        public array $matchedCategoryIds,
        public bool $containsTamil,
    ) {}

    public function hasTextQuery(): bool
    {
        return $this->normalizedQuery !== null && $this->normalizedQuery !== '';
    }
}
