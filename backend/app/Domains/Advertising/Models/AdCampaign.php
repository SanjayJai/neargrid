<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Models;

use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Models\Organization;
use App\Models\User;
use App\Support\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'organization_id',
    'created_by_user_id',
    'approved_by_admin_id',
    'name',
    'description',
    'campaign_type',
    'status',
    'priority',
    'starts_at',
    'ends_at',
    'daily_impression_cap',
    'total_impression_cap',
    'impressions_today',
    'impressions_today_date',
    'total_impressions',
    'total_clicks',
    'approved_at',
    'rejection_reason',
])]
#[Hidden([
    'id',
    'organization_id',
    'created_by_user_id',
    'approved_by_admin_id',
])]
class AdCampaign extends Model
{
    use HasPublicUlid, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'daily_impression_cap' => 'integer',
            'total_impression_cap' => 'integer',
            'impressions_today' => 'integer',
            'impressions_today_date' => 'date',
            'total_impressions' => 'integer',
            'total_clicks' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_admin_id');
    }

    public function creatives(): HasMany
    {
        return $this->hasMany(AdCreative::class, 'ad_campaign_id');
    }

    public function placements(): BelongsToMany
    {
        return $this->belongsToMany(AdPlacement::class, 'ad_campaign_placements')
            ->withPivot(['weight', 'is_active'])
            ->withTimestamps();
    }

    public function businesses(): BelongsToMany
    {
        return $this->belongsToMany(Business::class, 'ad_campaign_businesses')
            ->withPivot(['is_primary'])
            ->withTimestamps();
    }

    public function targetingRules(): HasMany
    {
        return $this->hasMany(AdTargetingRule::class, 'ad_campaign_id');
    }

    public function eventLogs(): HasMany
    {
        return $this->hasMany(AdEventLog::class, 'ad_campaign_id');
    }
}
