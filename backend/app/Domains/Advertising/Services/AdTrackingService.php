<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Services;

use App\Domains\Advertising\Models\AdCampaign;
use App\Domains\Advertising\Models\AdCreative;
use App\Domains\Advertising\Models\AdEventLog;
use App\Domains\Advertising\Models\AdPlacement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AdTrackingService
{
    private const TOKEN_TTL_SECONDS = 3600;

    private const IMPRESSION_DEDUP_SECONDS = 300;

    private const CLICK_DEDUP_SECONDS = 30;

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
     * @return array{recorded: bool, reason?: string, destination_url?: string}
     */
    public function recordEvent(
        string $token,
        string $eventType,
        ?string $userAgent,
        string $ip,
        string $sessionIdentifier,
    ): array {
        if (! in_array($eventType, ['impression', 'click'], true)) {
            return ['recorded' => false, 'reason' => 'invalid_event_type'];
        }

        $payload = $this->verifyToken($token);
        if ($payload === null || empty($payload['cid']) || empty($payload['pid'])) {
            return ['recorded' => false, 'reason' => 'invalid_or_expired_token'];
        }

        if ($this->isBot($userAgent)) {
            return ['recorded' => false, 'reason' => 'bot_filtered'];
        }

        $campaign = AdCampaign::query()->where('public_id', (string) $payload['cid'])->first();
        $placement = AdPlacement::query()->where('public_id', (string) $payload['pid'])->first();
        if (
            $campaign === null
            || $placement === null
            || $campaign->status !== 'active'
            || ! $placement->is_active
        ) {
            return ['recorded' => false, 'reason' => 'inactive_or_missing'];
        }

        $isAssigned = $campaign->placements()
            ->where('ad_placements.id', $placement->id)
            ->wherePivot('is_active', true)
            ->exists();
        if (! $isAssigned) {
            return ['recorded' => false, 'reason' => 'placement_unassigned'];
        }

        $creative = ! empty($payload['crid'])
            ? AdCreative::query()
                ->where('public_id', (string) $payload['crid'])
                ->where('ad_campaign_id', $campaign->id)
                ->where('status', 'active')
                ->first()
            : null;
        if (! empty($payload['crid']) && $creative === null) {
            return ['recorded' => false, 'reason' => 'creative_inactive'];
        }

        $safeDestination = $this->sanitizeDestinationUrl($creative?->destination_url);

        $ipHash = hash_hmac('sha256', $ip, $this->hmacKey());
        $sessionHash = hash_hmac('sha256', $sessionIdentifier !== '' ? $sessionIdentifier : $ip, $this->hmacKey());

        $dedupSeconds = $eventType === 'impression' ? self::IMPRESSION_DEDUP_SECONDS : self::CLICK_DEDUP_SECONDS;
        if ($this->isDuplicate($campaign->id, $placement->id, $eventType, $sessionHash, $dedupSeconds)) {
            return [
                'recorded' => false,
                'reason' => 'duplicate',
                'destination_url' => $safeDestination,
            ];
        }

        $now = CarbonImmutable::now('UTC');
        $today = $now->toDateString();

        $outcome = DB::transaction(function () use ($campaign, $creative, $placement, $payload, $eventType, $ipHash, $sessionHash, $now, $today): string {
            $locked = AdCampaign::query()->whereKey($campaign->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== 'active') {
                return 'inactive_or_missing';
            }

            if ($eventType === 'impression') {
                $sameDay = $locked->impressions_today_date?->toDateString() === $today;
                $currentToday = $sameDay ? (int) $locked->impressions_today : 0;

                if ($locked->daily_impression_cap !== null && $currentToday >= (int) $locked->daily_impression_cap) {
                    return 'cap_reached';
                }

                if ($locked->total_impression_cap !== null && (int) $locked->total_impressions >= (int) $locked->total_impression_cap) {
                    return 'cap_reached';
                }

                $locked->impressions_today = $currentToday + 1;
                $locked->impressions_today_date = $now->startOfDay();
                $locked->total_impressions = (int) $locked->total_impressions + 1;
                $locked->save();
            } else {
                AdCampaign::query()->whereKey($campaign->id)->increment('total_clicks');
            }

            AdEventLog::query()->create([
                'ad_campaign_id' => $campaign->id,
                'ad_creative_id' => $creative?->id,
                'ad_placement_id' => $placement->id,
                'domain_id' => isset($payload['dom']) ? (int) $payload['dom'] : null,
                'city_id' => isset($payload['city']) ? (int) $payload['city'] : null,
                'category_id' => isset($payload['cat']) ? (int) $payload['cat'] : null,
                'event_type' => $eventType,
                'device_type' => isset($payload['dev']) ? (string) $payload['dev'] : null,
                'audience_type' => isset($payload['aud']) ? (string) $payload['aud'] : null,
                'ip_hash' => $ipHash,
                'session_hash' => $sessionHash,
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

        $this->markDeduplicated($campaign->id, $placement->id, $eventType, $sessionHash, $dedupSeconds);

        return [
            'recorded' => true,
            'destination_url' => $safeDestination,
        ];
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

    private function isDuplicate(int $campaignId, int $placementId, string $eventType, string $sessionHash, int $windowSeconds): bool
    {
        $cacheKey = "ad_dedup:{$eventType}:{$campaignId}:{$placementId}:{$sessionHash}";
        try {
            if (Cache::has($cacheKey)) {
                return true;
            }
        } catch (Throwable) {
            // Fall through to DB check when cache/Redis is unavailable
        }

        return AdEventLog::query()
            ->where('ad_campaign_id', $campaignId)
            ->where('ad_placement_id', $placementId)
            ->where('event_type', $eventType)
            ->where('session_hash', $sessionHash)
            ->where('occurred_at', '>=', CarbonImmutable::now()->subSeconds($windowSeconds))
            ->exists();
    }

    private function markDeduplicated(int $campaignId, int $placementId, string $eventType, string $sessionHash, int $windowSeconds): void
    {
        $cacheKey = "ad_dedup:{$eventType}:{$campaignId}:{$placementId}:{$sessionHash}";
        try {
            Cache::put($cacheKey, 1, $windowSeconds);
        } catch (Throwable) {
            // Safe no-op if Redis/cache is unavailable
        }
    }

    private function hmacKey(): string
    {
        return (string) config('app.key', 'neargrid-ad-signing-key');
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
