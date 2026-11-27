<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Services;

use App\Domains\Advertising\DTOs\AdDeliveryContext;
use App\Domains\Advertising\Models\AdCampaign;
use App\Domains\Advertising\Models\AdCreative;
use App\Domains\Advertising\Models\AdPlacement;
use App\Domains\Advertising\Models\AdTargetingRule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class AdEligibilityService
{
    public function isCampaignEligible(AdCampaign $campaign, AdPlacement $placement, AdDeliveryContext $context, CarbonImmutable $now): bool
    {
        if ($campaign->status !== 'active') {
            return false;
        }

        if ($campaign->starts_at !== null && $campaign->starts_at->gt($now)) {
            return false;
        }

        if ($campaign->ends_at !== null && $campaign->ends_at->lte($now)) {
            return false;
        }

        if (! $this->hasRemainingImpressionCap($campaign, $now)) {
            return false;
        }

        // If campaign is linked to businesses, at least one must be active
        if ($campaign->relationLoaded('businesses') && $campaign->businesses->isNotEmpty()) {
            $hasActiveBusiness = $campaign->businesses->contains(
                static fn ($b): bool => ($b->status?->value ?? (string) $b->status) === 'active'
            );
            if (! $hasActiveBusiness) {
                return false;
            }
        }

        if ($this->selectCreative($campaign, $context->locale) === null) {
            return false;
        }

        return $this->matchesTargeting($campaign, $placement, $context);
    }

    public function hasRemainingImpressionCap(AdCampaign $campaign, CarbonImmutable $now): bool
    {
        if ($campaign->total_impression_cap !== null && $campaign->total_impressions >= $campaign->total_impression_cap) {
            return false;
        }

        if ($campaign->daily_impression_cap !== null) {
            $today = $now->utc()->toDateString();
            $todayDate = $campaign->impressions_today_date?->toDateString();
            if ($todayDate === $today && $campaign->impressions_today >= $campaign->daily_impression_cap) {
                return false;
            }
        }

        return true;
    }

    public function selectCreative(AdCampaign $campaign, string $locale): ?AdCreative
    {
        $creatives = $campaign->creatives->filter(
            static fn (AdCreative $cr): bool => $cr->status === 'active' && $cr->deleted_at === null
        );

        $preferred = $creatives->firstWhere('locale', $locale);
        if ($preferred instanceof AdCreative) {
            return $preferred;
        }

        $fallback = $creatives->firstWhere('locale', 'en');

        return $fallback instanceof AdCreative ? $fallback : null;
    }

    public function matchesTargeting(AdCampaign $campaign, AdPlacement $placement, AdDeliveryContext $context): bool
    {
        $rules = $campaign->targetingRules;
        if ($rules->isEmpty()) {
            return true;
        }

        $grouped = $rules->groupBy('target_type');

        foreach ($grouped as $targetType => $typeRules) {
            if ($targetType === 'category' && ! $placement->supports_category_targeting) {
                continue;
            }
            if (in_array($targetType, ['state', 'city', 'locality'], true) && ! $placement->supports_location_targeting) {
                continue;
            }
            if ($targetType === 'domain' && ! $placement->supports_domain_targeting) {
                continue;
            }

            $excludes = $typeRules->where('is_exclude', true);
            foreach ($excludes as $rule) {
                if ($this->ruleMatchesContext($rule, $context)) {
                    return false;
                }
            }

            $includes = $typeRules->where('is_exclude', false);
            if ($includes->isNotEmpty()) {
                $matched = $includes->contains(
                    fn (AdTargetingRule $rule): bool => $this->ruleMatchesContext($rule, $context)
                );
                if (! $matched) {
                    return false;
                }
            }
        }

        return true;
    }

    private function ruleMatchesContext(AdTargetingRule $rule, AdDeliveryContext $context): bool
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
            'device' => $rule->target_value === $context->device,
            'audience' => $rule->target_value === $context->audience,
            'route_pattern' => $rule->target_value !== null && Str::is((string) $rule->target_value, $context->routePath),
            default => false,
        };
    }
}
