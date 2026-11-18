<?php

declare(strict_types=1);

namespace App\Domains\Search\Services;

use App\Domains\Search\DTOs\ParsedQuery;
use App\Domains\Search\DTOs\SearchQuery;
use App\Domains\Search\Models\SearchQueryLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Records search performance metrics without storing sensitive user data
 * (no user IDs, IPs, phones, emails, or precise user coordinates).
 */
final class SearchPerformanceLogger
{
    public function record(
        SearchQuery $query,
        ParsedQuery $parsed,
        int $resultCount,
        int $durationMs,
        string $driver,
    ): void {
        if (! (bool) config('search.performance.log_queries', true)) {
            return;
        }

        $slowThreshold = (int) config('search.performance.slow_query_threshold_ms', 250);

        if ($durationMs >= $slowThreshold) {
            Log::warning('search.slow_query', [
                'driver' => $driver,
                'city_slug' => $query->citySlug,
                'category_slug' => $query->categorySlug,
                'has_geo' => $query->hasCoordinates(),
                'sort' => $query->sort->value,
                'result_count' => $resultCount,
                'duration_ms' => $durationMs,
            ]);
        }

        try {
            SearchQueryLog::query()->create([
                'normalized_query' => $parsed->normalizedQuery !== null ? Str::limit($parsed->normalizedQuery, 160, '') : null,
                'display_query' => $parsed->originalQuery !== null ? Str::limit($parsed->originalQuery, 160, '') : null,
                'city_slug' => $query->citySlug,
                'category_slug' => $query->categorySlug,
                'locality_slug' => $query->localitySlugs[0] ?? null,
                'locale' => $query->locale,
                'has_geo' => $query->hasCoordinates(),
                'sort_by' => $query->sort->value,
                'result_count' => $resultCount,
                'duration_ms' => max(0, $durationMs),
                'engine_driver' => $driver,
            ]);
        } catch (Throwable) {
            // Telemetry persistence must never break live customer search.
        }
    }
}
