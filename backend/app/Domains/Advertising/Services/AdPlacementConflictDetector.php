<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Services;

use App\Domains\Advertising\Models\AdPlacement;
use Illuminate\Support\Collection;

final class AdPlacementConflictDetector
{
    /**
     * Detect active placements that share the same page_type + slot_position with overlapping device/audience scopes.
     *
     * @param  Collection<int, AdPlacement>|null  $placements
     * @return list<array<string, mixed>>
     */
    public function detectConflicts(?Collection $placements = null): array
    {
        $active = ($placements ?? AdPlacement::query()->where('is_active', true)->orderBy('sort_order')->get())
            ->filter(static fn (AdPlacement $p): bool => $p->is_active)
            ->values();

        $conflicts = [];
        $count = $active->count();

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $active[$i];
                $b = $active[$j];

                if ($a->page_type !== $b->page_type || $a->slot_position !== $b->slot_position) {
                    continue;
                }

                if (! $this->scopesOverlap($a->device_scope, $b->device_scope)) {
                    continue;
                }

                if (! $this->scopesOverlap($a->audience_scope, $b->audience_scope)) {
                    continue;
                }

                $conflicts[] = [
                    'page_type' => $a->page_type,
                    'slot_position' => $a->slot_position,
                    'placement_a' => ['public_id' => $a->public_id, 'key' => $a->key, 'name' => $a->name],
                    'placement_b' => ['public_id' => $b->public_id, 'key' => $b->key, 'name' => $b->name],
                    'reason' => "Both '{$a->key}' and '{$b->key}' target {$a->page_type}:{$a->slot_position} with overlapping device ({$a->device_scope}/{$b->device_scope}) and audience ({$a->audience_scope}/{$b->audience_scope}) scopes.",
                ];
            }
        }

        return $conflicts;
    }

    private function scopesOverlap(string $scopeA, string $scopeB): bool
    {
        return $scopeA === 'all' || $scopeB === 'all' || $scopeA === $scopeB;
    }
}
