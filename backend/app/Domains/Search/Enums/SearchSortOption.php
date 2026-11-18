<?php

declare(strict_types=1);

namespace App\Domains\Search\Enums;

enum SearchSortOption: string
{
    case Relevance = 'relevance';
    case Rating = 'rating';
    case Distance = 'distance';
    case Popularity = 'popularity';
    case Newest = 'newest';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
