<?php

declare(strict_types=1);

namespace App\Domains\Promotions\Models;

use App\Support\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

#[Fillable([
    'promo_notice_id',
    'locale',
    'badge_text',
    'title',
    'subtitle',
    'body',
    'cta_label',
    'secondary_cta_label',
    'destination_url',
    'image_url',
    'alt_text',
    'meta_json',
])]
#[Hidden(['id', 'promo_notice_id'])]
class PromoNoticeLocale extends Model
{
    use HasPublicUlid;

    protected static function booted(): void
    {
        static::saving(function (self $record): void {
            if (! in_array($record->locale, ['en', 'ta'], true)) {
                throw new InvalidArgumentException("Unsupported promo notice locale: {$record->locale}");
            }

            foreach (['badge_text', 'title', 'subtitle', 'body', 'cta_label', 'secondary_cta_label', 'alt_text'] as $textField) {
                $val = $record->{$textField};
                if ($val !== null && $val !== '' && self::containsExecutableMarkup((string) $val)) {
                    throw new InvalidArgumentException("Executable HTML or script markup is forbidden in {$textField}.");
                }
            }

            if ($record->destination_url !== null && $record->destination_url !== '') {
                self::assertSafeUrl($record->destination_url, 'destination_url');
            }
            if ($record->image_url !== null && $record->image_url !== '') {
                self::assertSafeUrl($record->image_url, 'image_url');
            }
        });
    }

    private static function containsExecutableMarkup(string $value): bool
    {
        return preg_match('/<\s*\/?\s*(script|iframe|object|embed|svg|style|link|meta)\b|on[a-z]+\s*=|javascript:/i', $value) === 1;
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

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<PromoNotice, $this>
     */
    public function notice(): BelongsTo
    {
        return $this->belongsTo(PromoNotice::class, 'promo_notice_id');
    }
}
