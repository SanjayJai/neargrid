<?php

declare(strict_types=1);

namespace App\Domains\Promotions\Models;

use App\Domains\Advertising\Models\AdCampaign;
use App\Models\User;
use App\Support\Concerns\HasPublicUlid;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

#[Fillable([
    'kind',
    'notice_type',
    'display_format',
    'severity',
    'status',
    'is_enabled',
    'priority',
    'trigger_type',
    'trigger_delay_seconds',
    'trigger_scroll_percent',
    'starts_at',
    'ends_at',
    'is_dismissible',
    'cooldown_hours',
    'max_displays_per_session',
    'max_displays_per_user',
    'max_total_impressions',
    'impressions_count',
    'clicks_count',
    'dismissals_count',
    'ad_campaign_id',
    'created_by_user_id',
    'updated_by_user_id',
    'approved_by_admin_id',
    'approved_at',
    'rejection_reason',
])]
#[Hidden([
    'id',
    'ad_campaign_id',
    'created_by_user_id',
    'updated_by_user_id',
    'approved_by_admin_id',
])]
class PromoNotice extends Model
{
    use HasPublicUlid, SoftDeletes;

    /** @var array<string, mixed> */
    protected $attributes = [
        'severity' => 'info',
        'status' => 'draft',
        'is_enabled' => true,
        'priority' => 100,
        'trigger_type' => 'page_load',
        'trigger_delay_seconds' => 0,
        'is_dismissible' => true,
        'cooldown_hours' => 24,
        'impressions_count' => 0,
        'clicks_count' => 0,
        'dismissals_count' => 0,
    ];

    public const VALID_KINDS = ['popup', 'announcement'];

    public const VALID_NOTICE_TYPES = [
        'welcome',
        'promo',
        'exit_intent',
        'login_prompt',
        'location_prompt',
        'owner_prompt',
        'service_notice',
        'maintenance',
        'global_bar',
    ];

    public const VALID_DISPLAY_FORMATS = ['modal', 'slide_in', 'top_bar', 'bottom_bar', 'inline'];

    public const VALID_SEVERITIES = ['info', 'promo', 'warning', 'maintenance'];

    public const VALID_STATUSES = [
        'draft',
        'pending_approval',
        'approved',
        'scheduled',
        'active',
        'paused',
        'expired',
        'archived',
    ];

    public const VALID_TRIGGERS = ['page_load', 'delay', 'scroll', 'exit_intent', 'login', 'registration'];

    protected static function booted(): void
    {
        static::saving(function (self $notice): void {
            if (! in_array($notice->kind, self::VALID_KINDS, true)) {
                throw new InvalidArgumentException("Invalid promo notice kind: {$notice->kind}");
            }
            if (! in_array($notice->notice_type, self::VALID_NOTICE_TYPES, true)) {
                throw new InvalidArgumentException("Invalid promo notice_type: {$notice->notice_type}");
            }
            if (! in_array($notice->display_format, self::VALID_DISPLAY_FORMATS, true)) {
                throw new InvalidArgumentException("Invalid display_format: {$notice->display_format}");
            }
            if (! in_array($notice->severity, self::VALID_SEVERITIES, true)) {
                throw new InvalidArgumentException("Invalid severity: {$notice->severity}");
            }
            if (! in_array($notice->status, self::VALID_STATUSES, true)) {
                throw new InvalidArgumentException("Invalid status: {$notice->status}");
            }
            if (! in_array($notice->trigger_type, self::VALID_TRIGGERS, true)) {
                throw new InvalidArgumentException("Invalid trigger_type: {$notice->trigger_type}");
            }
            if ($notice->starts_at !== null && $notice->ends_at !== null && $notice->ends_at->lte($notice->starts_at)) {
                throw new InvalidArgumentException('Promo notice ends_at must be after starts_at.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'priority' => 'integer',
            'trigger_delay_seconds' => 'integer',
            'trigger_scroll_percent' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_dismissible' => 'boolean',
            'cooldown_hours' => 'integer',
            'max_displays_per_session' => 'integer',
            'max_displays_per_user' => 'integer',
            'max_total_impressions' => 'integer',
            'impressions_count' => 'integer',
            'clicks_count' => 'integer',
            'dismissals_count' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active')->where('is_enabled', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLiveAt(Builder $query, CarbonInterface $at): Builder
    {
        return $query->active()
            ->where(static function (Builder $q) use ($at): void {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $at);
            })
            ->where(static function (Builder $q) use ($at): void {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', $at);
            });
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind);
    }

    /**
     * @return HasMany<PromoNoticeLocale, $this>
     */
    public function locales(): HasMany
    {
        return $this->hasMany(PromoNoticeLocale::class, 'promo_notice_id');
    }

    /**
     * @return HasMany<PromoNoticeTargetingRule, $this>
     */
    public function targetingRules(): HasMany
    {
        return $this->hasMany(PromoNoticeTargetingRule::class, 'promo_notice_id');
    }

    /**
     * @return HasMany<PromoNoticeEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(PromoNoticeEvent::class, 'promo_notice_id');
    }

    /**
     * @return BelongsTo<AdCampaign, $this>
     */
    public function adCampaign(): BelongsTo
    {
        return $this->belongsTo(AdCampaign::class, 'ad_campaign_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_admin_id');
    }
}
