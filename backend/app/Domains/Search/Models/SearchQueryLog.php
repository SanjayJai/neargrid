<?php

declare(strict_types=1);

namespace App\Domains\Search\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Privacy-safe search performance & trending telemetry log.
 * Never stores user IDs, IP addresses, phones, emails, or precise user coordinates.
 */
#[Fillable([
    'normalized_query',
    'display_query',
    'city_slug',
    'category_slug',
    'locality_slug',
    'locale',
    'has_geo',
    'sort_by',
    'result_count',
    'duration_ms',
    'engine_driver',
])]
class SearchQueryLog extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'has_geo' => 'boolean',
            'result_count' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
