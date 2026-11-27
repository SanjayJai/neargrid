<?php

declare(strict_types=1);

namespace App\Domains\Promotions\Models;

use App\Domains\Domains\Models\Domain;
use App\Domains\Location\Models\City;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

#[Fillable([
    'promo_notice_id',
    'promo_notice_locale_id',
    'domain_id',
    'city_id',
    'event_type',
    'locale',
    'device_type',
    'audience_type',
    'ip_hash',
    'session_hash',
    'user_hash',
    'occurred_at',
])]
#[Hidden(['id', 'ip_hash', 'session_hash', 'user_hash'])]
class PromoNoticeEvent extends Model
{
    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    public const VALID_EVENT_TYPES = ['impression', 'click', 'dismiss'];

    protected static function booted(): void
    {
        static::saving(function (self $event): void {
            if (! in_array($event->event_type, self::VALID_EVENT_TYPES, true)) {
                throw new InvalidArgumentException("Invalid promo notice event_type: {$event->event_type}");
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    public function notice(): BelongsTo
    {
        return $this->belongsTo(PromoNotice::class, 'promo_notice_id');
    }

    public function noticeLocale(): BelongsTo
    {
        return $this->belongsTo(PromoNoticeLocale::class, 'promo_notice_locale_id');
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class, 'domain_id');
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    public static function pruneOlderThan(CarbonInterface $cutoff): int
    {
        return static::query()->where('occurred_at', '<', $cutoff)->delete();
    }
}
