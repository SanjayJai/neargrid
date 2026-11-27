<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Models;

use App\Support\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'key',
    'name',
    'description',
    'page_type',
    'route_pattern',
    'slot_position',
    'render_format',
    'device_scope',
    'audience_scope',
    'max_ads',
    'width_px',
    'height_px',
    'supports_category_targeting',
    'supports_location_targeting',
    'supports_domain_targeting',
    'is_active',
    'sort_order',
    'config_json',
])]
#[Hidden(['id'])]
class AdPlacement extends Model
{
    use HasPublicUlid, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_ads' => 'integer',
            'width_px' => 'integer',
            'height_px' => 'integer',
            'supports_category_targeting' => 'boolean',
            'supports_location_targeting' => 'boolean',
            'supports_domain_targeting' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'config_json' => 'array',
        ];
    }

    /**
     * @return BelongsToMany<AdCampaign, $this>
     */
    public function campaigns(): BelongsToMany
    {
        return $this->belongsToMany(AdCampaign::class, 'ad_campaign_placements')
            ->withPivot(['weight', 'is_active'])
            ->withTimestamps();
    }
}
