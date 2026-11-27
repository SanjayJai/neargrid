<?php

declare(strict_types=1);

namespace App\Domains\Promotions\Services;

use App\Domains\Advertising\Models\AdPlacement;
use App\Domains\Promotions\Models\PromoNotice;
use App\Domains\Promotions\Models\PromoNoticeTargetingRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class PromoNoticeConflictDetector
{
    /**
     * Detect active/scheduled promo notices that share the same kind + display_format
     * with overlapping schedules and overlapping targeting dimensions.
     *
     * @param  Collection<int, PromoNotice>|null  $notices
     * @return list<array<string, mixed>>
     */
    public function detectConflicts(?Collection $notices = null): array
    {
        $candidates = ($notices ?? PromoNotice::query()
            ->whereIn('status', ['active', 'scheduled'])
            ->where('is_enabled', true)
            ->with('targetingRules')
            ->orderByDesc('priority')
            ->get())
            ->values();

        $conflicts = [];
        $count = $candidates->count();

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $candidates[$i];
                $b = $candidates[$j];

                if ($a->kind !== $b->kind || $a->display_format !== $b->display_format) {
                    continue;
                }

                if (! $this->schedulesOverlap($a, $b)) {
                    continue;
                }

                if (! $this->dimensionOverlaps($a->targetingRules, $b->targetingRules, 'device')) {
                    continue;
                }

                if (! $this->dimensionOverlaps($a->targetingRules, $b->targetingRules, 'audience')) {
                    continue;
                }

                if (! $this->fkDimensionOverlaps($a->targetingRules, $b->targetingRules, 'domain', 'domain_id')) {
                    continue;
                }

                if (! $this->fkDimensionOverlaps($a->targetingRules, $b->targetingRules, 'city', 'city_id')) {
                    continue;
                }

                if (! $this->routesOverlap($a->targetingRules, $b->targetingRules)) {
                    continue;
                }

                $conflicts[] = [
                    'source' => 'promo_notice',
                    'kind' => $a->kind,
                    'display_format' => $a->display_format,
                    'notice_a' => ['public_id' => $a->public_id, 'notice_type' => $a->notice_type, 'priority' => $a->priority],
                    'notice_b' => ['public_id' => $b->public_id, 'notice_type' => $b->notice_type, 'priority' => $b->priority],
                    'reason' => "Notices '{$a->public_id}' and '{$b->public_id}' share {$a->kind}:{$a->display_format} with overlapping schedule and targeting scopes.",
                ];
            }
        }

        // Detect M5 global.top_announcement_link vs M6 top_bar announcement conflicts
        $topBarAdPlacement = AdPlacement::query()
            ->where('key', 'global.top_announcement_link')
            ->where('is_active', true)
            ->with(['campaigns' => static fn ($q) => $q->whereIn('ad_campaigns.status', ['active', 'scheduled'])->whereNull('ad_campaigns.deleted_at')])
            ->first();

        if ($topBarAdPlacement !== null && $topBarAdPlacement->campaigns->isNotEmpty()) {
            foreach ($candidates as $notice) {
                if ($notice->kind !== 'announcement' || $notice->display_format !== 'top_bar') {
                    continue;
                }

                $conflicts[] = [
                    'source' => 'm5_ad_placement',
                    'kind' => 'announcement',
                    'display_format' => 'top_bar',
                    'placement_key' => 'global.top_announcement_link',
                    'notice' => ['public_id' => $notice->public_id, 'notice_type' => $notice->notice_type, 'priority' => $notice->priority],
                    'reason' => "M6 top_bar announcement '{$notice->public_id}' overlaps with active M5 placement 'global.top_announcement_link'.",
                ];
            }
        }

        return $conflicts;
    }

    /**
     * @param  Collection<int, PromoNoticeTargetingRule>  $rulesA
     * @param  Collection<int, PromoNoticeTargetingRule>  $rulesB
     */
    private function routesOverlap(Collection $rulesA, Collection $rulesB): bool
    {
        $routesA = $rulesA->where('target_type', 'route_pattern')->where('is_exclude', false)->pluck('target_value')->filter()->all();
        $routesB = $rulesB->where('target_type', 'route_pattern')->where('is_exclude', false)->pluck('target_value')->filter()->all();

        if ($routesA === [] || $routesB === []) {
            return true;
        }

        foreach ($routesA as $ra) {
            foreach ($routesB as $rb) {
                if ($ra === $rb || Str::is((string) $ra, (string) $rb) || Str::is((string) $rb, (string) $ra)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function schedulesOverlap(PromoNotice $a, PromoNotice $b): bool
    {
        if ($a->ends_at !== null && $b->starts_at !== null && $a->ends_at->lte($b->starts_at)) {
            return false;
        }
        if ($b->ends_at !== null && $a->starts_at !== null && $b->ends_at->lte($a->starts_at)) {
            return false;
        }

        return true;
    }

    /**
     * @param  Collection<int, PromoNoticeTargetingRule>  $rulesA
     * @param  Collection<int, PromoNoticeTargetingRule>  $rulesB
     */
    private function dimensionOverlaps(Collection $rulesA, Collection $rulesB, string $type): bool
    {
        $valsA = $rulesA->where('target_type', $type)->where('is_exclude', false)->pluck('target_value')->filter()->all();
        $valsB = $rulesB->where('target_type', $type)->where('is_exclude', false)->pluck('target_value')->filter()->all();

        if ($valsA === [] || $valsB === []) {
            return true;
        }

        return array_intersect($valsA, $valsB) !== [];
    }

    /**
     * @param  Collection<int, PromoNoticeTargetingRule>  $rulesA
     * @param  Collection<int, PromoNoticeTargetingRule>  $rulesB
     */
    private function fkDimensionOverlaps(Collection $rulesA, Collection $rulesB, string $type, string $column): bool
    {
        $idsA = $rulesA->where('target_type', $type)->where('is_exclude', false)->pluck($column)->filter()->map('intval')->all();
        $idsB = $rulesB->where('target_type', $type)->where('is_exclude', false)->pluck($column)->filter()->map('intval')->all();

        if ($idsA === [] || $idsB === []) {
            return true;
        }

        return array_intersect($idsA, $idsB) !== [];
    }
}
