<?php

declare(strict_types=1);

namespace App\Domains\Seo\Services;

final class IndexabilityGate
{
    /**
     * Evaluate deterministic indexability precedence (highest priority first).
     * Lower layers (including admin overrides) can restrict indexing, never force-index a blocked page.
     *
     * @param  array{
     *     is_active?: bool,
     *     lifecycle_status?: string|null,
     *     is_soft_deleted?: bool,
     *     is_canonical_host?: bool,
     *     has_non_canonical_filters?: bool,
     *     page?: int,
     *     listing_count?: int|null,
     *     min_listings?: int,
     *     completeness_score?: int|null,
     *     min_completeness_score?: int,
     *     has_landing_page?: bool,
     *     override_robots_index?: bool|null,
     *     override_robots_follow?: bool|null,
     * }  $context
     * @return array{index: bool, follow: bool, robots: string, reason: string}
     */
    public function evaluate(array $context): array
    {
        $isActive = (bool) ($context['is_active'] ?? true);
        $lifecycle = (string) ($context['lifecycle_status'] ?? 'published');
        $isSoftDeleted = (bool) ($context['is_soft_deleted'] ?? false);

        // 1. Lifecycle / visibility hard block
        if (! $isActive || $isSoftDeleted || $lifecycle !== 'published') {
            return [
                'index' => false,
                'follow' => false,
                'robots' => 'noindex, nofollow',
                'reason' => 'inactive_or_unpublished',
            ];
        }

        // 2. Host / canonical mismatch
        if (($context['is_canonical_host'] ?? true) === false) {
            return [
                'index' => false,
                'follow' => true,
                'robots' => 'noindex, follow',
                'reason' => 'non_canonical_host',
            ];
        }

        // 3. Query / crawl-budget rules
        $page = (int) ($context['page'] ?? 1);
        $maxPage = (int) config('directory.seo.max_indexed_pagination_page', 50);
        if (($context['has_non_canonical_filters'] ?? false) === true || $page > $maxPage) {
            return [
                'index' => false,
                'follow' => true,
                'robots' => 'noindex, follow',
                'reason' => 'filtered_or_deep_pagination',
            ];
        }

        // 4. Thin-content & non-landing thresholds
        if (($context['has_landing_page'] ?? true) === false) {
            return [
                'index' => false,
                'follow' => true,
                'robots' => 'noindex, follow',
                'reason' => 'non_landing_node',
            ];
        }

        if (isset($context['listing_count'])) {
            $minListings = (int) ($context['min_listings'] ?? config('directory.seo.min_listings_for_locality_category_index', 3));
            if ((int) $context['listing_count'] < $minListings) {
                return [
                    'index' => false,
                    'follow' => true,
                    'robots' => 'noindex, follow',
                    'reason' => 'thin_listing_count',
                ];
            }
        }

        if (isset($context['completeness_score'])) {
            $minScore = (int) ($context['min_completeness_score'] ?? config('directory.seo.min_completeness_score_for_profile_index', 40));
            if ((int) $context['completeness_score'] < $minScore) {
                return [
                    'index' => false,
                    'follow' => true,
                    'robots' => 'noindex, follow',
                    'reason' => 'thin_profile_score',
                ];
            }
        }

        // 5. Explicit admin seo_metadata override (can restrict, never force index above rules 1-4)
        $index = ($context['override_robots_index'] ?? true) !== false;
        $follow = ($context['override_robots_follow'] ?? true) !== false;

        return [
            'index' => $index,
            'follow' => $follow,
            'robots' => ($index ? 'index' : 'noindex').', '.($follow ? 'follow' : 'nofollow'),
            'reason' => $index ? 'eligible' : 'admin_override_noindex',
        ];
    }
}
