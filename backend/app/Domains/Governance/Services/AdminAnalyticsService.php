<?php

declare(strict_types=1);

namespace App\Domains\Governance\Services;

use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Models\BusinessClaim;
use App\Domains\Directory\Models\BusinessVerification;
use App\Domains\Domains\Models\Domain;
use App\Domains\Governance\Models\AuditLog;
use App\Domains\Leads\Models\Enquiry;
use App\Domains\Location\Models\City;
use App\Domains\Reviews\Models\Review;
use App\Domains\Reviews\Models\ReviewReport;
use App\Domains\Search\Models\SearchQueryLog;
use App\Domains\Taxonomy\Models\Category;
use App\Domains\Taxonomy\Models\CategoryCollection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class AdminAnalyticsService
{
    /**
     * @return array<string, mixed>
     */
    public function overview(
        string $range = '30d',
        ?string $fromDate = null,
        ?string $toDate = null,
        ?string $citySlug = null,
    ): array {
        [
            $currentStart,
            $currentExclusiveEnd,
            $currentInclusiveTo,
            $prevStart,
            $prevExclusiveEnd,
            $prevInclusiveTo,
            $days,
        ] = $this->resolveWindow($range, $fromDate, $toDate);

        $city = null;
        if ($citySlug !== null && $citySlug !== '') {
            $city = City::query()->where('slug', $citySlug)->first();
        }
        $cityId = $city?->id;

        // 1. Businesses aggregate (single query, half-open [start, exclusiveEnd) intervals)
        $bizAgg = Business::query()
            ->when($cityId !== null, static fn ($q) => $q->where('city_id', $cityId))
            ->selectRaw("
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_count,
                SUM(CASE WHEN status = 'pending_review' THEN 1 ELSE 0 END) AS pending_review_count,
                SUM(CASE WHEN is_verified = 1 THEN 1 ELSE 0 END) AS verified_count,
                SUM(CASE WHEN is_trusted = 1 THEN 1 ELSE 0 END) AS trusted_count,
                SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) AS current_new,
                SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) AS prev_new
            ", [$currentStart, $currentExclusiveEnd, $prevStart, $prevExclusiveEnd])
            ->first();

        // 2. Users aggregate (single query)
        $userAgg = User::query()
            ->selectRaw("
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_count,
                SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) AS suspended_count,
                SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) AS current_new,
                SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) AS prev_new
            ", [$currentStart, $currentExclusiveEnd, $prevStart, $prevExclusiveEnd])
            ->first();

        // 3. Enquiries aggregate (single query)
        $enqAgg = Enquiry::query()
            ->when($cityId !== null, static fn ($q) => $q->where('city_id', $cityId))
            ->selectRaw("
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS dispatched_count,
                SUM(CASE WHEN status = 'pending_otp' THEN 1 ELSE 0 END) AS pending_otp_count,
                SUM(CASE WHEN status IN ('cancelled', 'expired') THEN 1 ELSE 0 END) AS closed_count,
                SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) AS current_new,
                SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) AS prev_new
            ", [$currentStart, $currentExclusiveEnd, $prevStart, $prevExclusiveEnd])
            ->first();

        // 4. Reviews & reports aggregates (single query each)
        $revAgg = Review::query()
            ->when($cityId !== null, static fn ($q) => $q->whereHas('business', static fn ($b) => $b->where('city_id', $cityId)))
            ->selectRaw("
                COUNT(*) AS total,
                SUM(CASE WHEN moderation_status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
                SUM(CASE WHEN moderation_status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN moderation_status IN ('flagged', 'rejected') THEN 1 ELSE 0 END) AS flagged_count,
                AVG(CASE WHEN status = 'published' THEN rating ELSE NULL END) AS avg_rating,
                SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) AS current_new,
                SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) AS prev_new
            ", [$currentStart, $currentExclusiveEnd, $prevStart, $prevExclusiveEnd])
            ->first();

        $openReportsCount = ReviewReport::query()
            ->where('status', 'pending')
            ->when($cityId !== null, static fn ($q) => $q->whereHas('review.business', static fn ($b) => $b->where('city_id', $cityId)))
            ->count();

        // 5. KYC & Claims aggregates
        $verifAgg = BusinessVerification::query()
            ->when($cityId !== null, static fn ($q) => $q->whereHas('business', static fn ($b) => $b->where('city_id', $cityId)))
            ->selectRaw("
                SUM(CASE WHEN status IN ('pending', 'under_review') THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count
            ")
            ->first();

        $pendingClaimsCount = BusinessClaim::query()
            ->where('status', 'pending')
            ->when($cityId !== null, static fn ($q) => $q->whereHas('business', static fn ($b) => $b->where('city_id', $cityId)))
            ->count();

        // 6. Domains & Taxonomy aggregates
        $domainAgg = Domain::query()
            ->selectRaw("
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_count,
                SUM(CASE WHEN verification_status = 'verified' THEN 1 ELSE 0 END) AS verified_count,
                SUM(CASE WHEN ssl_status = 'active' THEN 1 ELSE 0 END) AS ssl_active_count
            ")
            ->first();

        $catAgg = Category::query()
            ->selectRaw("
                COUNT(*) AS total,
                SUM(CASE WHEN lifecycle_status = 'published' THEN 1 ELSE 0 END) AS published_count
            ")
            ->first();

        $collectionsTotal = CategoryCollection::query()->count();

        // 7. Search Query Logs aggregate (half-open [start, exclusiveEnd))
        $searchAgg = SearchQueryLog::query()
            ->where('created_at', '>=', $currentStart)
            ->where('created_at', '<', $currentExclusiveEnd)
            ->when($city !== null, static fn ($q) => $q->where('city_slug', $city->slug))
            ->selectRaw('
                COUNT(*) AS total_searches,
                AVG(duration_ms) AS avg_duration_ms,
                SUM(CASE WHEN result_count = 0 THEN 1 ELSE 0 END) AS zero_result_count
            ')
            ->first();

        $prevSearchesCount = SearchQueryLog::query()
            ->where('created_at', '>=', $prevStart)
            ->where('created_at', '<', $prevExclusiveEnd)
            ->when($city !== null, static fn ($q) => $q->where('city_slug', $city->slug))
            ->count();

        $topQueries = SearchQueryLog::query()
            ->where('created_at', '>=', $currentStart)
            ->where('created_at', '<', $currentExclusiveEnd)
            ->whereNotNull('display_query')
            ->where('display_query', '!=', '')
            ->when($city !== null, static fn ($q) => $q->where('city_slug', $city->slug))
            ->selectRaw('display_query AS query, COUNT(*) AS search_count, AVG(result_count) AS avg_results')
            ->groupBy('display_query')
            ->orderByDesc('search_count')
            ->limit(8)
            ->get()
            ->map(static fn ($r): array => [
                'query' => (string) $r->query,
                'search_count' => (int) $r->search_count,
                'avg_results' => round((float) $r->avg_results, 1),
            ])
            ->values()
            ->all();

        $topCities = SearchQueryLog::query()
            ->where('created_at', '>=', $currentStart)
            ->where('created_at', '<', $currentExclusiveEnd)
            ->whereNotNull('city_slug')
            ->where('city_slug', '!=', '')
            ->selectRaw('city_slug, COUNT(*) AS search_count')
            ->groupBy('city_slug')
            ->orderByDesc('search_count')
            ->limit(8)
            ->get()
            ->map(static fn ($r): array => [
                'city_slug' => (string) $r->city_slug,
                'search_count' => (int) $r->search_count,
            ])
            ->values()
            ->all();

        // 8. Daily Trend Series (date-grouped SQL aggregates, zero N+1)
        $dailyTrends = $this->buildDailyTrends($currentStart, $currentExclusiveEnd, $currentInclusiveTo, $cityId, $city?->slug);

        // 9. Safe Recent Activity (no old_values, new_values, ip_address, or actor_user_id)
        $recentActivity = AuditLog::query()
            ->orderByDesc('id')
            ->limit(8)
            ->get(['id', 'actor_role', 'action', 'auditable_type', 'auditable_id', 'created_at'])
            ->map(static fn (AuditLog $log): array => [
                'id' => $log->id,
                'actor_role' => $log->actor_role,
                'action' => $log->action,
                'auditable_type' => $log->auditable_type,
                'auditable_id' => $log->auditable_id,
                'created_at' => $log->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $bizCurrent = (int) ($bizAgg?->current_new ?? 0);
        $bizPrev = (int) ($bizAgg?->prev_new ?? 0);
        $userCurrent = (int) ($userAgg?->current_new ?? 0);
        $userPrev = (int) ($userAgg?->prev_new ?? 0);
        $enqCurrent = (int) ($enqAgg?->current_new ?? 0);
        $enqPrev = (int) ($enqAgg?->prev_new ?? 0);
        $revCurrent = (int) ($revAgg?->current_new ?? 0);
        $revPrev = (int) ($revAgg?->prev_new ?? 0);
        $searchCurrent = (int) ($searchAgg?->total_searches ?? 0);

        $enqTotal = (int) ($enqAgg?->total ?? 0);
        $enqDispatched = (int) ($enqAgg?->dispatched_count ?? 0);

        return [
            'period' => [
                'range' => $range,
                'from' => $currentStart->toDateString(),
                'to' => $currentInclusiveTo->toDateString(),
                'previous_from' => $prevStart->toDateString(),
                'previous_to' => $prevInclusiveTo->toDateString(),
                'days' => $days,
                'city_slug' => $city?->slug,
            ],
            'summary' => [
                'businesses' => [
                    'total' => (int) ($bizAgg?->total ?? 0),
                    'active' => (int) ($bizAgg?->active_count ?? 0),
                    'pending_review' => (int) ($bizAgg?->pending_review_count ?? 0),
                    'verified' => (int) ($bizAgg?->verified_count ?? 0),
                    'trusted' => (int) ($bizAgg?->trusted_count ?? 0),
                    'comparison' => $this->comparison($bizCurrent, $bizPrev),
                ],
                'users' => [
                    'total' => (int) ($userAgg?->total ?? 0),
                    'active' => (int) ($userAgg?->active_count ?? 0),
                    'suspended' => (int) ($userAgg?->suspended_count ?? 0),
                    'comparison' => $this->comparison($userCurrent, $userPrev),
                ],
                'enquiries' => [
                    'total' => $enqTotal,
                    'dispatched' => $enqDispatched,
                    'pending_otp' => (int) ($enqAgg?->pending_otp_count ?? 0),
                    'closed' => (int) ($enqAgg?->closed_count ?? 0),
                    'dispatch_rate_percent' => $enqTotal > 0 ? round(($enqDispatched / $enqTotal) * 100, 1) : 0.0,
                    'comparison' => $this->comparison($enqCurrent, $enqPrev),
                ],
                'reviews' => [
                    'total' => (int) ($revAgg?->total ?? 0),
                    'approved' => (int) ($revAgg?->approved_count ?? 0),
                    'pending_moderation' => (int) ($revAgg?->pending_count ?? 0),
                    'flagged' => (int) ($revAgg?->flagged_count ?? 0),
                    'avg_rating' => $revAgg?->avg_rating !== null ? round((float) $revAgg->avg_rating, 2) : null,
                    'open_reports' => $openReportsCount,
                    'comparison' => $this->comparison($revCurrent, $revPrev),
                ],
                'kyc_and_claims' => [
                    'pending_verifications' => (int) ($verifAgg?->pending_count ?? 0),
                    'approved_verifications' => (int) ($verifAgg?->approved_count ?? 0),
                    'rejected_verifications' => (int) ($verifAgg?->rejected_count ?? 0),
                    'pending_claims' => $pendingClaimsCount,
                ],
                'domains' => [
                    'total' => (int) ($domainAgg?->total ?? 0),
                    'active' => (int) ($domainAgg?->active_count ?? 0),
                    'verified' => (int) ($domainAgg?->verified_count ?? 0),
                    'ssl_active' => (int) ($domainAgg?->ssl_active_count ?? 0),
                ],
                'categories' => [
                    'total' => (int) ($catAgg?->total ?? 0),
                    'published' => (int) ($catAgg?->published_count ?? 0),
                    'collections' => $collectionsTotal,
                ],
                'search' => [
                    'total_searches' => $searchCurrent,
                    'avg_duration_ms' => $searchAgg?->avg_duration_ms !== null
                        ? (int) round((float) $searchAgg->avg_duration_ms)
                        : 0,
                    'zero_result_searches' => (int) ($searchAgg?->zero_result_count ?? 0),
                    'comparison' => $this->comparison($searchCurrent, $prevSearchesCount),
                ],
            ],
            'daily_trends' => $dailyTrends,
            'top_queries' => $topQueries,
            'top_cities' => $topCities,
            'recent_activity' => $recentActivity,
            'unavailable_integrations' => [
                [
                    'key' => 'ga4',
                    'label' => 'Google Analytics 4 (Traffic & Sessions)',
                    'status' => 'not_configured',
                    'reason' => 'External GA4 integration is not configured.',
                ],
                [
                    'key' => 'gsc',
                    'label' => 'Google Search Console (Organic Impressions & Clicks)',
                    'status' => 'not_configured',
                    'reason' => 'External GSC integration is not configured.',
                ],
                [
                    'key' => 'ad_campaigns',
                    'label' => 'Advertising Placements & Campaign CTR',
                    'status' => 'pending_milestone',
                    'reason' => 'Advertising placement & campaign analytics are scheduled for M-F2 M5.',
                ],
            ],
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: CarbonImmutable, 3: CarbonImmutable, 4: CarbonImmutable, 5: CarbonImmutable, 6: int}
     */
    private function resolveWindow(string $range, ?string $fromDate, ?string $toDate): array
    {
        $now = CarbonImmutable::now('UTC');

        if ($range === 'custom' && $fromDate !== null && $toDate !== null) {
            $start = CarbonImmutable::parse($fromDate, 'UTC')->startOfDay();
            $inclusiveTo = CarbonImmutable::parse($toDate, 'UTC')->startOfDay();
        } else {
            $days = match ($range) {
                '7d' => 7,
                '90d' => 90,
                default => 30,
            };
            $inclusiveTo = $now->startOfDay();
            $start = $inclusiveTo->subDays($days - 1)->startOfDay();
        }

        $exclusiveEnd = $inclusiveTo->addDay();
        $totalDays = max(1, (int) $start->diffInDays($inclusiveTo) + 1);
        $prevExclusiveEnd = $start;
        $prevStart = $start->subDays($totalDays)->startOfDay();
        $prevInclusiveTo = $start->subDay()->startOfDay();

        return [$start, $exclusiveEnd, $inclusiveTo, $prevStart, $prevExclusiveEnd, $prevInclusiveTo, $totalDays];
    }

    /**
     * @return array{current: int, previous: int, delta_count: int, delta_percent: ?float}
     */
    private function comparison(int $current, int $previous): array
    {
        $delta = $current - $previous;
        $percent = $previous > 0
            ? round(($delta / $previous) * 100, 1)
            : ($current > 0 ? 100.0 : 0.0);

        return [
            'current' => $current,
            'previous' => $previous,
            'delta_count' => $delta,
            'delta_percent' => $percent,
        ];
    }

    /**
     * @return list<array<string, int|string>>
     */
    private function buildDailyTrends(
        CarbonImmutable $start,
        CarbonImmutable $exclusiveEnd,
        CarbonImmutable $inclusiveTo,
        ?int $cityId,
        ?string $citySlug,
    ): array {
        $bizByDay = DB::table('businesses')
            ->whereNull('deleted_at')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $exclusiveEnd)
            ->when($cityId !== null, static fn ($q) => $q->where('city_id', $cityId))
            ->selectRaw('DATE(created_at) AS day, COUNT(*) AS cnt')
            ->groupByRaw('DATE(created_at)')
            ->pluck('cnt', 'day');

        $usersByDay = DB::table('users')
            ->whereNull('deleted_at')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $exclusiveEnd)
            ->selectRaw('DATE(created_at) AS day, COUNT(*) AS cnt')
            ->groupByRaw('DATE(created_at)')
            ->pluck('cnt', 'day');

        $enqByDay = DB::table('enquiries')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $exclusiveEnd)
            ->when($cityId !== null, static fn ($q) => $q->where('city_id', $cityId))
            ->selectRaw('DATE(created_at) AS day, COUNT(*) AS cnt')
            ->groupByRaw('DATE(created_at)')
            ->pluck('cnt', 'day');

        $revByDay = DB::table('reviews')
            ->when($cityId !== null, static function ($q) use ($cityId): void {
                $q->join('businesses', 'businesses.id', '=', 'reviews.business_id')
                    ->where('businesses.city_id', $cityId);
            })
            ->where('reviews.created_at', '>=', $start)
            ->where('reviews.created_at', '<', $exclusiveEnd)
            ->selectRaw('DATE(reviews.created_at) AS day, COUNT(*) AS cnt')
            ->groupByRaw('DATE(reviews.created_at)')
            ->pluck('cnt', 'day');

        $searchByDay = DB::table('search_query_logs')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $exclusiveEnd)
            ->when($citySlug !== null, static fn ($q) => $q->where('city_slug', $citySlug))
            ->selectRaw('DATE(created_at) AS day, COUNT(*) AS cnt')
            ->groupByRaw('DATE(created_at)')
            ->pluck('cnt', 'day');

        $series = [];
        $cursor = $start->startOfDay();
        $lastDay = $inclusiveTo->startOfDay();

        while ($cursor->lte($lastDay)) {
            $dayKey = $cursor->toDateString();
            $series[] = [
                'date' => $dayKey,
                'businesses' => (int) ($bizByDay[$dayKey] ?? 0),
                'users' => (int) ($usersByDay[$dayKey] ?? 0),
                'enquiries' => (int) ($enqByDay[$dayKey] ?? 0),
                'reviews' => (int) ($revByDay[$dayKey] ?? 0),
                'searches' => (int) ($searchByDay[$dayKey] ?? 0),
            ];
            $cursor = $cursor->addDay();
        }

        return $series;
    }
}
