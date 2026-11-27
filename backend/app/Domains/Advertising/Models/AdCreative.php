<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Models;

use App\Support\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

#[Fillable([
    'ad_campaign_id',
    'title',
    'subtitle',
    'cta_label',
    'destination_url',
    'image_url',
    'mobile_image_url',
    'alt_text',
    'locale',
    'status',
])]
#[Hidden(['id', 'ad_campaign_id'])]
class AdCreative extends Model
{
    use HasPublicUlid, SoftDeletes;

    protected static function booted(): void
    {
        static::saving(function (self $creative): void {
            self::assertSafeUrl($creative->destination_url, 'destination_url');

            if ($creative->image_url !== null && $creative->image_url !== '') {
                self::assertSafeUrl($creative->image_url, 'image_url');
            }
            if ($creative->mobile_image_url !== null && $creative->mobile_image_url !== '') {
                self::assertSafeUrl($creative->mobile_image_url, 'mobile_image_url');
            }

            if (! in_array($creative->locale, ['en', 'ta'], true)) {
                throw new InvalidArgumentException("Unsupported creative locale: {$creative->locale}");
            }
        });
    }

    private static function assertSafeUrl(string $url, string $field): void
    {
        $trimmed = trim($url);
        if ($trimmed === '' || str_starts_with($trimmed, '//')) {
            throw new InvalidArgumentException("Unsafe {$field}: protocol-relative or empty URL.");
        }

        if (str_starts_with($trimmed, '/')) {
            if (str_contains($trimmed, '\\') || preg_match('/[\x00-\x1F]/', $trimmed) === 1) {
                throw new InvalidArgumentException("Unsafe internal {$field}.");
            }

            return;
        }

        $scheme = strtolower((string) parse_url($trimmed, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true) || filter_var($trimmed, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException("Unsafe {$field}: only internal paths or http/https URLs are allowed.");
        }
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AdCampaign::class, 'ad_campaign_id');
    }
}
