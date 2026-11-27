<?php

declare(strict_types=1);

namespace App\Domains\Promotions\Services;

use App\Domains\Promotions\DTOs\PromoNoticeDeliveryContext;
use App\Domains\Promotions\Models\PromoNotice;
use App\Domains\Promotions\Models\PromoNoticeEvent;
use App\Domains\Promotions\Models\PromoNoticeLocale;
use App\Domains\Promotions\Models\PromoNoticeTargetingRule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class PromoNoticeEligibilityService
{
    public function isNoticeEligible(
        PromoNotice $notice,
        PromoNoticeDeliveryContext $context,
        CarbonImmutable $now,
        ?string $sessionHash = null,
        ?string $userHash = null,
    ): bool {
        if ($notice->status !== 'active' || ! $notice->is_enabled) {
            return false;
        }

        if ($notice->starts_at !== null && $notice->starts_at->gt($now)) {
            return false;
        }

        if ($notice->ends_at !== null && $notice->ends_at->lte($now)) {
            return false;
        }

        if ($notice->max_total_impressions !== null && (int) $notice->impressions_count >= (int) $notice->max_total_impressions) {
            return false;
        }

        if ($this->selectLocale($notice, $context->locale) === null) {
            return false;
        }

        if (! $this->matchesTargeting($notice, $context)) {
            return false;
        }

        return $this->passesFrequencyLimits($notice, $now, $sessionHash, $userHash);
    }

    public function selectLocale(PromoNotice $notice, string $locale): ?PromoNoticeLocale
    {
        $preferred = $notice->locales->firstWhere('locale', $locale);
        if ($preferred instanceof PromoNoticeLocale) {
            return $preferred;
        }

        $fallback = $notice->locales->firstWhere('locale', 'en');

        return $fallback instanceof PromoNoticeLocale ? $fallback : null;
    }

    public function passesFrequencyLimits(
        PromoNotice $notice,
        CarbonImmutable $now,
        ?string $sessionHash,
        ?string $userHash,
    ): bool {
        $actorHash = $userHash ?? $sessionHash;

        // Check dismissal cooldown
        if ($notice->is_dismissible && $notice->cooldown_hours > 0 && $actorHash !== null) {
            $cutoff = $now->subHours((int) $notice->cooldown_hours);
            $dismissedRecently = PromoNoticeEvent::query()
                ->where('promo_notice_id', $notice->id)
                ->where('event_type', 'dismiss')
                ->where('occurred_at', '>=', $cutoff)
                ->where(function ($q) use ($sessionHash, $userHash): void {
                    if ($userHash !== null && $sessionHash !== null) {
                        $q->where('user_hash', $userHash)->orWhere('session_hash', $sessionHash);
                    } elseif ($userHash !== null) {
                        $q->where('user_hash', $userHash);
                    } else {
                        $q->where('session_hash', $sessionHash);
                    }
                })
                ->exists();

            if ($dismissedRecently) {
                return false;
            }
        }

        // Check per-session impression cap (last 24h for the session hash)
        if ($notice->max_displays_per_session !== null && $sessionHash !== null) {
            $sessionCount = PromoNoticeEvent::query()
                ->where('promo_notice_id', $notice->id)
                ->where('event_type', 'impression')
                ->where('session_hash', $sessionHash)
                ->where('occurred_at', '>=', $now->subHours(24))
                ->count();

            if ($sessionCount >= (int) $notice->max_displays_per_session) {
                return false;
            }
        }

        // Check per-user lifetime impression cap
        if ($notice->max_displays_per_user !== null && $userHash !== null) {
            $userCount = PromoNoticeEvent::query()
                ->where('promo_notice_id', $notice->id)
                ->where('event_type', 'impression')
                ->where('user_hash', $userHash)
                ->count();

            if ($userCount >= (int) $notice->max_displays_per_user) {
                return false;
            }
        }

        return true;
    }

    public function matchesTargeting(PromoNotice $notice, PromoNoticeDeliveryContext $context): bool
    {
        $rules = $notice->targetingRules;
        if ($rules->isEmpty()) {
            return true;
        }

        $grouped = $rules->groupBy('target_type');

        foreach ($grouped as $typeRules) {
            $excludes = $typeRules->where('is_exclude', true);
            foreach ($excludes as $rule) {
                if ($this->ruleMatchesContext($rule, $context)) {
                    return false;
                }
            }

            $includes = $typeRules->where('is_exclude', false);
            if ($includes->isNotEmpty()) {
                $matched = $includes->contains(
                    fn (PromoNoticeTargetingRule $rule): bool => $this->ruleMatchesContext($rule, $context)
                );
                if (! $matched) {
                    return false;
                }
            }
        }

        return true;
    }

    private function ruleMatchesContext(PromoNoticeTargetingRule $rule, PromoNoticeDeliveryContext $context): bool
    {
        return match ($rule->target_type) {
            'category' => $context->categoryId !== null && (
                (int) $rule->category_id === $context->categoryId
                || ($rule->include_descendants && in_array((int) $rule->category_id, $context->categoryAncestorIds, true))
            ),
            'state' => $context->stateId !== null && (int) $rule->state_id === $context->stateId,
            'city' => $context->cityId !== null && (int) $rule->city_id === $context->cityId,
            'locality' => $context->localityId !== null && (int) $rule->locality_id === $context->localityId,
            'domain' => $context->domainId !== null && (int) $rule->domain_id === $context->domainId,
            'business' => $context->businessId !== null && (int) $rule->business_id === $context->businessId,
            'device' => $rule->target_value === $context->device,
            'audience' => $rule->target_value === $context->audience,
            'route_pattern' => $rule->target_value !== null && Str::is((string) $rule->target_value, $context->routePath),
            default => false,
        };
    }
}
