<?php

declare(strict_types=1);

namespace App\Domains\Search\Services;

use App\Domains\Directory\Models\Business;
use App\Domains\Search\DTOs\ParsedQuery;
use App\Domains\Search\DTOs\SearchHit;
use App\Domains\Search\Enums\SearchSortOption;
use Carbon\CarbonImmutable;

final class SearchRankingService
{
    /**
     * Compute Bayesian damped rating in [0.0, 5.0].
     * Prevents a brand-new listing with 1 five-star review from unfairly outranking
     * an established high-rating listing with many reviews.
     */
    public function bayesianRating(float $ratingAvg, int $reviewCount): float
    {
        $priorMean = (float) config('search.ranking.bayesian.prior_mean', 3.5);
        $priorWeight = (float) config('search.ranking.bayesian.prior_weight', 10);

        if ($reviewCount <= 0) {
            return round($priorMean, 4);
        }

        $clampedRating = max(0.0, min(5.0, $ratingAvg));

        return round((($reviewCount * $clampedRating) + ($priorWeight * $priorMean)) / ($reviewCount + $priorWeight), 4);
    }

    /**
     * Score an organic business deterministically in [0.0, 1.0].
     *
     * @param  list<int>  $targetCategoryIds
     */
    public function scoreOrganic(
        Business $business,
        ParsedQuery $parsedQuery,
        array $targetCategoryIds,
        ?float $distanceKm,
        float $mysqlFullTextScore = 0.0,
        float $maxBatchFullTextScore = 1.0,
    ): float {
        /** @var array<string, float> $weights */
        $weights = (array) config('search.ranking.weights', [
            'text' => 0.32,
            'category_service' => 0.13,
            'geo' => 0.20,
            'rating' => 0.15,
            'trust' => 0.10,
            'completeness' => 0.06,
            'recency' => 0.04,
        ]);

        $textScore = $this->computeTextScore($business, $parsedQuery, $mysqlFullTextScore, $maxBatchFullTextScore);
        $categoryServiceScore = $this->computeCategoryAndServiceScore($business, $parsedQuery, $targetCategoryIds);
        $geoScore = $this->computeGeoScore($distanceKm);
        $ratingScore = $this->bayesianRating((float) $business->rating_avg, (int) $business->review_count) / 5.0;
        $trustScore = $this->computeTrustScore($business);
        $completenessScore = max(0.0, min(100.0, (float) $business->completeness_score)) / 100.0;
        $recencyScore = $this->computeRecencyScore($business);

        $composite = ($weights['text'] ?? 0.32) * $textScore
            + ($weights['category_service'] ?? 0.13) * $categoryServiceScore
            + ($weights['geo'] ?? 0.20) * $geoScore
            + ($weights['rating'] ?? 0.15) * $ratingScore
            + ($weights['trust'] ?? 0.10) * $trustScore
            + ($weights['completeness'] ?? 0.06) * $completenessScore
            + ($weights['recency'] ?? 0.04) * $recencyScore;

        return round(max(0.0, min(1.0, $composite)), 6);
    }

    /**
     * Sort SearchHits deterministically according to the validated sort option.
     *
     * @param  list<SearchHit>  $hits
     * @return list<SearchHit>
     */
    public function sortHits(array $hits, SearchSortOption $sort): array
    {
        usort($hits, function (SearchHit $a, SearchHit $b) use ($sort): int {
            $primary = match ($sort) {
                SearchSortOption::Relevance => $b->relevanceScore <=> $a->relevanceScore,
                SearchSortOption::Rating => $this->compareRating($a, $b),
                SearchSortOption::Distance => $this->compareDistance($a, $b),
                SearchSortOption::Popularity => $this->comparePopularity($a, $b),
                SearchSortOption::Newest => ($b->business->created_at?->getTimestamp() ?? 0) <=> ($a->business->created_at?->getTimestamp() ?? 0),
            };

            if ($primary !== 0) {
                return $primary;
            }

            // Secondary deterministic tie-breakers
            $scoreTie = $b->relevanceScore <=> $a->relevanceScore;
            if ($scoreTie !== 0) {
                return $scoreTie;
            }

            return (int) $a->business->id <=> (int) $b->business->id;
        });

        return $hits;
    }

    private function compareRating(SearchHit $a, SearchHit $b): int
    {
        $bayesA = $this->bayesianRating((float) $a->business->rating_avg, (int) $a->business->review_count);
        $bayesB = $this->bayesianRating((float) $b->business->rating_avg, (int) $b->business->review_count);

        $cmp = $bayesB <=> $bayesA;
        if ($cmp !== 0) {
            return $cmp;
        }

        return (int) $b->business->review_count <=> (int) $a->business->review_count;
    }

    private function compareDistance(SearchHit $a, SearchHit $b): int
    {
        $distA = $a->distanceKm ?? INF;
        $distB = $b->distanceKm ?? INF;

        $cmp = $distA <=> $distB;
        if ($cmp !== 0) {
            return $cmp;
        }

        return $this->compareRating($a, $b);
    }

    private function comparePopularity(SearchHit $a, SearchHit $b): int
    {
        $cmp = (int) $b->business->review_count <=> (int) $a->business->review_count;
        if ($cmp !== 0) {
            return $cmp;
        }

        return (int) $b->business->view_count <=> (int) $a->business->view_count;
    }

    private function computeTextScore(
        Business $business,
        ParsedQuery $parsedQuery,
        float $mysqlFullTextScore,
        float $maxBatchFullTextScore,
    ): float {
        if (! $parsedQuery->hasTextQuery()) {
            return 0.70;
        }

        $q = (string) $parsedQuery->normalizedQuery;
        $name = mb_strtolower((string) $business->name, 'UTF-8');

        if ($name === $q) {
            return 1.00;
        }

        if (str_starts_with($name, $q)) {
            return 0.92;
        }

        if (str_contains($name, $q)) {
            return 0.84;
        }

        // Check normalized MySQL FULLTEXT relevance score if present
        $ftNormalized = $mysqlFullTextScore > 0.0 && $maxBatchFullTextScore > 0.0
            ? min(0.85, 0.85 * ($mysqlFullTextScore / $maxBatchFullTextScore))
            : 0.0;

        // Evaluate token coverage across name, tagline, description, search_keywords
        $haystack = mb_strtolower(
            implode(' ', array_filter([
                $business->name,
                $business->tagline,
                $business->description,
                $business->search_keywords,
            ])),
            'UTF-8'
        );

        $terms = $parsedQuery->expandedTerms !== [] ? $parsedQuery->expandedTerms : $parsedQuery->tokens;
        $matchedTokens = 0;
        $nameTokenHits = 0;

        foreach ($terms as $term) {
            if ($term !== '' && str_contains($name, $term)) {
                $nameTokenHits++;
                $matchedTokens++;
            } elseif ($term !== '' && str_contains($haystack, $term)) {
                $matchedTokens++;
            }
        }

        $tokenScore = 0.0;
        if ($nameTokenHits > 0) {
            $tokenScore = min(0.82, 0.60 + ($nameTokenHits * 0.11));
        } elseif ($matchedTokens > 0) {
            $tokenScore = min(0.72, 0.45 + ($matchedTokens * 0.09));
        }

        return max($ftNormalized, $tokenScore);
    }

    /**
     * @param  list<int>  $targetCategoryIds
     */
    private function computeCategoryAndServiceScore(
        Business $business,
        ParsedQuery $parsedQuery,
        array $targetCategoryIds,
    ): float {
        $score = 0.0;

        $allCategoryTargets = array_values(array_unique([...$targetCategoryIds, ...$parsedQuery->matchedCategoryIds]));

        if ($allCategoryTargets !== []) {
            if (in_array((int) $business->primary_category_id, $allCategoryTargets, true)) {
                $score = max($score, 1.00);
            } elseif ($business->relationLoaded('categories')) {
                foreach ($business->categories as $category) {
                    if (in_array((int) $category->id, $allCategoryTargets, true)) {
                        $score = max($score, 0.80);
                        break;
                    }
                }
            }
        } else {
            $score = 0.50;
        }

        if ($parsedQuery->hasTextQuery() && $business->relationLoaded('services')) {
            $terms = $parsedQuery->expandedTerms !== [] ? $parsedQuery->expandedTerms : $parsedQuery->tokens;
            foreach ($business->services as $service) {
                if (! $service->is_active) {
                    continue;
                }
                $serviceText = mb_strtolower($service->name.' '.($service->description ?? ''), 'UTF-8');
                foreach ($terms as $term) {
                    if ($term !== '' && str_contains($serviceText, $term)) {
                        $score = max($score, 0.85);
                        break 2;
                    }
                }
            }
        }

        return $score;
    }

    private function computeGeoScore(?float $distanceKm): float
    {
        if ($distanceKm === null) {
            return 0.50;
        }

        $halfLife = max(0.5, (float) config('search.geo.distance_decay_half_life_km', 5.0));

        return 1.0 / (1.0 + (max(0.0, $distanceKm) / $halfLife));
    }

    private function computeTrustScore(Business $business): float
    {
        if ($business->is_verified && $business->is_trusted) {
            return 1.00;
        }

        if ($business->is_verified) {
            return 0.75;
        }

        if ($business->is_claimed) {
            return 0.40;
        }

        return 0.15;
    }

    private function computeRecencyScore(Business $business): float
    {
        $updated = $business->updated_at ?? $business->created_at;
        if ($updated === null) {
            return 0.50;
        }

        $halfLifeDays = max(1.0, (float) config('search.ranking.recency_half_life_days', 180));
        $ageDays = max(0.0, CarbonImmutable::parse($updated)->diffInSeconds(CarbonImmutable::now()) / 86400.0);

        return 1.0 / (1.0 + ($ageDays / $halfLifeDays));
    }
}
