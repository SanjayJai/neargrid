<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Models;

use App\Domains\Domains\Models\Domain;
use App\Domains\Location\Models\City;
use App\Domains\Taxonomy\Models\Category;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'ad_campaign_id',
    'ad_creative_id',
    'ad_placement_id',
    'domain_id',
    'city_id',
    'category_id',
    'event_type',
    'device_type',
    'audience_type',
    'ip_hash',
    'session_hash',
    'occurred_at',
])]
#[Hidden(['id', 'ip_hash', 'session_hash'])]
class AdEventLog extends Model
{
    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AdCampaign::class, 'ad_campaign_id');
    }

    public function creative(): BelongsTo
    {
        return $this->belongsTo(AdCreative::class, 'ad_creative_id');
    }

    public function placement(): BelongsTo
    {
        return $this->belongsTo(AdPlacement::class, 'ad_placement_id');
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class, 'domain_id');
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public static function pruneOlderThan(CarbonInterface $cutoff): int
    {
        return static::query()->where('occurred_at', '<', $cutoff)->delete();
    }
}
