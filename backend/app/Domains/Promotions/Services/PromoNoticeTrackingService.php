<?php

declare(strict_types=1);

namespace App\Domains\Promotions\Services;

use App\Domains\Promotions\Models\PromoNotice;
use App\Domains\Promotions\Models\PromoNoticeEvent;
use App\Domains\Promotions\Models\PromoNoticeLocale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PromoNoticeTrackingService
{
    private const TOKEN_TTL_SECONDS = 3600;

    private const IMPRESSION_DEDUP_SECONDS = 300;

    private const CLICK_DEDUP_SECONDS = 30;

    private const DISMISS_DEDUP_SECONDS = 60;

    /**
     * @param  array<string, int|string|null>  $payload
     */
    public function mintToken(array $payload): string
    {
        $data = array_merge($payload, ['exp' => time() + self::TOKEN_TTL_SECONDS]);
        $encoded = $this->base64UrlEncode((string) json_encode($data, JSON_THROW_ON_ERROR));
        $sig = hash_hmac('sha256', $encoded, $this->hmacKey());

        return "{$encoded}.{$sig}";
    }

    /**
     * @return array<string, mixed>|null
     */
    public function verifyToken(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }

        [$encoded, $sig] = $parts;
        $expected = hash_hmac('sha256', $encoded, $this->hmacKey());
        if (! hash_equals($expected, $sig)) {
            return null;
        }

        $decoded = $this->base64UrlDecode($encoded);
        if ($decoded === null) {
            return null;
        }

        $payload = json_decode($decoded, true);
        if (! is_array($payload) || ! isset($payload['exp']) || (int) $payload['exp'] < time()) {
            return null;
        }

        return $payload;
    }

    public function hashIdentifier(string $raw): string
    {
        return hash_hmac('sha256', $raw, $this->hmacKey());
    }

    public function isBot(?string $userAgent): bool
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return true;
        }

        return preg_match(
            '/(bot|crawl|spider|slurp|headless|phantomjs|puppeteer|playwright|curl|wget|python-requests|lighthouse|pagespeed)/i',
            $userAgent
        ) === 1;
    }

    /**
     * @return array{recorded: bool, reason?: string, destination_url?: string|null}
     */
    public function recordEvent(
        string $token,
        string $eventType,
        ?string $userAgent,
        string $ip,
        string $sessionIdentifier,
        ?int $userId = null,
    ): array {
        if (! in_array($eventType, PromoNoticeEvent::VALID_EVENT_TYPES, true)) {
            return ['recorded' => false, 'reason' => 'invalid_event_type'];
        }

        $payload = $this->verifyToken($token);
        if ($payload === null || empty($payload['nid'])) {
            return ['recorded' => false, 'reason' => 'invalid_or_expired_token'];
        }

        if ($this->isBot($userAgent)) {
            return ['recorded' => false, 'reason' => 'bot_filtered'];
        }

        $notice = PromoNotice::query()->where('public_id', (string) $payload['nid'])->first();
        if ($notice === null || $notice->status !== 'active' || ! $notice->is_enabled) {
            return ['recorded' => false, 'reason' => 'inactive_or_missing'];
        }

        $localeRecord = ! empty($payload['lid'])
            ? PromoNoticeLocale::query()
                ->where('public_id', (string) $payload['lid'])
                ->where('promo_notice_id', $notice->id)
                ->first()
            : null;
        if (! empty($payload['lid']) && $localeRecord === null) {
            return ['recorded' => false, 'reason' => 'locale_missing'];
        }

        $safeDestination = $this->sanitizeDestinationUrl($localeRecord?->destination_url);

        $ipHash = $this->hashIdentifier($ip);
        $sessionHash = $this->hashIdentifier($sessionIdentifier !== '' ? $sessionIdentifier : $ip);
        $userHash = $userId !== null ? $this->hashIdentifier((string) $userId) : null;

        $dedupSeconds = match ($eventType) {
            'impression' => self::IMPRESSION_DEDUP_SECONDS,
            'click' => self::CLICK_DEDUP_SECONDS,
            'dismiss' => self::DISMISS_DEDUP_SECONDS,
        };

        if ($this->isDuplicate($notice->id, $eventType, $sessionHash, $dedupSeconds)) {
            return [
                'recorded' => false,
                'reason' => 'duplicate',
                'destination_url' => $safeDestination,
            ];
        }

        $now = CarbonImmutable::now('UTC');

        $outcome = DB::transaction(function () use ($notice, $localeRecord, $payload, $eventType, $ipHash, $sessionHash, $userHash, $now): string {
            $locked = PromoNotice::query()->whereKey($notice->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== 'active' || ! $locked->is_enabled) {
                return 'inactive_or_missing';
            }

            if ($locked->starts_at !== null && $locked->starts_at->gt($now)) {
                return 'not_started';
            }

            if ($locked->ends_at !== null && $locked->ends_at->lte($now)) {
                return 'expired';
            }

            if ($eventType === 'impression') {
                if ($locked->max_total_impressions !== null && (int) $locked->impressions_count >= (int) $locked->max_total_impressions) {
                    return 'cap_reached';
                }

                if ($locked->is_dismissible && $locked->cooldown_hours > 0) {
                    $cutoff = $now->subHours((int) $locked->cooldown_hours);
                    $dismissedRecently = PromoNoticeEvent::query()
                        ->where('promo_notice_id', $locked->id)
                        ->where('event_type', 'dismiss')
                        ->where('occurred_at', '>=', $cutoff)
                        ->where(function ($q) use ($sessionHash, $userHash): void {
                            if ($userHash !== null) {
                                $q->where('user_hash', $userHash)->orWhere('session_hash', $sessionHash);
                            } else {
                                $q->where('session_hash', $sessionHash);
                            }
                        })
                        ->exists();

                    if ($dismissedRecently) {
                        return 'dismissed_cooldown';
                    }
                }

                if ($locked->max_displays_per_session !== null) {
                    $sessionCount = PromoNoticeEvent::query()
                        ->where('promo_notice_id', $locked->id)
                        ->where('event_type', 'impression')
                        ->where('session_hash', $sessionHash)
                        ->where('occurred_at', '>=', $now->subHours(24))
                        ->count();

                    if ($sessionCount >= (int) $locked->max_displays_per_session) {
                        return 'session_cap_reached';
                    }
                }

                if ($locked->max_displays_per_user !== null && $userHash !== null) {
                    $userCount = PromoNoticeEvent::query()
                        ->where('promo_notice_id', $locked->id)
                        ->where('event_type', 'impression')
                        ->where('user_hash', $userHash)
                        ->count();

                    if ($userCount >= (int) $locked->max_displays_per_user) {
                        return 'user_cap_reached';
                    }
                }

                $locked->impressions_count = (int) $locked->impressions_count + 1;
                $locked->save();
            } elseif ($eventType === 'click') {
                $locked->clicks_count = (int) $locked->clicks_count + 1;
                $locked->save();
            } else {
                if (! $locked->is_dismissible) {
                    return 'not_dismissible';
                }
                $locked->dismissals_count = (int) $locked->dismissals_count + 1;
                $locked->save();
            }

            PromoNoticeEvent::query()->create([
                'promo_notice_id' => $locked->id,
                'promo_notice_locale_id' => $localeRecord?->id,
                'domain_id' => isset($payload['dom']) ? (int) $payload['dom'] : null,
                'city_id' => isset($payload['city']) ? (int) $payload['city'] : null,
                'event_type' => $eventType,
                'locale' => $localeRecord?->locale,
                'device_type' => isset($payload['dev']) ? (string) $payload['dev'] : null,
                'audience_type' => isset($payload['aud']) ? (string) $payload['aud'] : null,
                'ip_hash' => $ipHash,
                'session_hash' => $sessionHash,
                'user_hash' => $userHash,
                'occurred_at' => $now,
            ]);

            return 'recorded';
        });

        if ($outcome !== 'recorded') {
            return [
                'recorded' => false,
                'reason' => $outcome,
                'destination_url' => $safeDestination,
            ];
        }

        $this->markDeduplicated($notice->id, $eventType, $sessionHash, $dedupSeconds);

        return [
            'recorded' => true,
            'destination_url' => $safeDestination,
        ];
    }

    private function isDuplicate(int $noticeId, string $eventType, string $sessionHash, int $windowSeconds): bool
    {
        $cacheKey = "promo_dedup:{$eventType}:{$noticeId}:{$sessionHash}";
        try {
            if (Cache::has($cacheKey)) {
                return true;
            }
        } catch (Throwable) {
            // Fall back to DB check when Redis/cache is unavailable
        }

        return PromoNoticeEvent::query()
            ->where('promo_notice_id', $noticeId)
            ->where('event_type', $eventType)
            ->where('session_hash', $sessionHash)
            ->where('occurred_at', '>=', CarbonImmutable::now('UTC')->subSeconds($windowSeconds))
            ->exists();
    }

    private function markDeduplicated(int $noticeId, string $eventType, string $sessionHash, int $windowSeconds): void
    {
        $cacheKey = "promo_dedup:{$eventType}:{$noticeId}:{$sessionHash}";
        try {
            Cache::put($cacheKey, 1, $windowSeconds);
        } catch (Throwable) {
            // Safe no-op if Redis/cache is unavailable
        }
    }

    private function sanitizeDestinationUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $trimmed = trim($url);
        if ($trimmed === '' || str_starts_with($trimmed, '//')) {
            return null;
        }
        if (str_starts_with($trimmed, '/')) {
            return (str_contains($trimmed, '\\') || preg_match('/[\x00-\x1F]/', $trimmed) === 1) ? null : $trimmed;
        }
        $scheme = strtolower((string) parse_url($trimmed, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) && filter_var($trimmed, FILTER_VALIDATE_URL) !== false
            ? $trimmed
            : null;
    }

    private function hmacKey(): string
    {
        return (string) config('app.key', 'neargrid-promo-signing-key');
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): ?string
    {
        $padded = str_pad(strtr($data, '-_', '+/'), strlen($data) % 4 === 0 ? strlen($data) : strlen($data) + 4 - (strlen($data) % 4), '=');
        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }
}
