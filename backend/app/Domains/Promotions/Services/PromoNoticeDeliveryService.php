<?php

declare(strict_types=1);

namespace App\Domains\Promotions\Services;

use App\Domains\Promotions\DTOs\PromoNoticeDeliveryContext;
use App\Domains\Promotions\Models\PromoNotice;
use Carbon\CarbonImmutable;

final class PromoNoticeDeliveryService
{
    public function __construct(
        private readonly PromoNoticeEligibilityService $eligibility,
        private readonly PromoNoticeTrackingService $tracking,
    ) {}

    /**
     * Deliver eligible announcements (max 3) and at most 1 highest-priority popup.
     *
     * @return array{announcements: list<array<string, mixed>>, popup: array<string, mixed>|null}
     */
    public function deliver(PromoNoticeDeliveryContext $context): array
    {
        $now = CarbonImmutable::now('UTC');
        $sessionHash = $context->sessionIdentifier !== ''
            ? $this->tracking->hashIdentifier($context->sessionIdentifier)
            : null;
        $userHash = $context->userId !== null
            ? $this->tracking->hashIdentifier((string) $context->userId)
            : null;

        $notices = PromoNotice::query()
            ->liveAt($now)
            ->with(['locales', 'targetingRules'])
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();

        $eligibleAnnouncements = [];
        $eligiblePopups = [];

        foreach ($notices as $notice) {
            if (! $this->eligibility->isNoticeEligible($notice, $context, $now, $sessionHash, $userHash)) {
                continue;
            }

            $serialized = $this->serializeForPublic($notice, $context);
            if ($serialized === null) {
                continue;
            }

            $sortTuple = [
                $this->severityRank($notice),
                (int) $notice->priority,
                -$notice->id,
            ];

            if ($notice->kind === 'announcement') {
                $eligibleAnnouncements[] = ['rank' => $sortTuple, 'payload' => $serialized];
            } else {
                $eligiblePopups[] = ['rank' => $sortTuple, 'payload' => $serialized];
            }
        }

        usort($eligibleAnnouncements, static fn (array $a, array $b): int => $b['rank'] <=> $a['rank']);
        usort($eligiblePopups, static fn (array $a, array $b): int => $b['rank'] <=> $a['rank']);

        return [
            'announcements' => array_slice(
                array_map(static fn (array $item): array => $item['payload'], $eligibleAnnouncements),
                0,
                3
            ),
            'popup' => $eligiblePopups[0]['payload'] ?? null,
        ];
    }

    private function severityRank(PromoNotice $notice): int
    {
        if ($notice->severity === 'maintenance' || $notice->notice_type === 'maintenance') {
            return 4;
        }
        if ($notice->severity === 'warning' || $notice->notice_type === 'service_notice') {
            return 3;
        }
        if ($notice->severity === 'promo') {
            return 2;
        }

        return 1;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializeForPublic(PromoNotice $notice, PromoNoticeDeliveryContext $context): ?array
    {
        $locale = $this->eligibility->selectLocale($notice, $context->locale);
        if ($locale === null) {
            return null;
        }

        $token = $this->tracking->mintToken([
            'nid' => $notice->public_id,
            'lid' => $locale->public_id,
            'dom' => $context->domainId,
            'city' => $context->cityId,
            'dev' => $context->device,
            'aud' => $context->audience,
        ]);

        return [
            'public_id' => $notice->public_id,
            'kind' => $notice->kind,
            'notice_type' => $notice->notice_type,
            'display_format' => $notice->display_format,
            'severity' => $notice->severity,
            'trigger_type' => $notice->trigger_type,
            'trigger_delay_seconds' => (int) $notice->trigger_delay_seconds,
            'trigger_scroll_percent' => $notice->trigger_scroll_percent,
            'is_dismissible' => (bool) $notice->is_dismissible,
            'cooldown_hours' => (int) $notice->cooldown_hours,
            'max_displays_per_session' => $notice->max_displays_per_session,
            'locale' => $locale->locale,
            'badge_text' => $locale->badge_text,
            'title' => $locale->title,
            'subtitle' => $locale->subtitle,
            'body' => $locale->body,
            'cta_label' => $locale->cta_label,
            'secondary_cta_label' => $locale->secondary_cta_label,
            'destination_url' => $locale->destination_url,
            'image_url' => $locale->image_url,
            'alt_text' => $locale->alt_text ?? $locale->title,
            'tracking_token' => $token,
        ];
    }
}
