<?php

declare(strict_types=1);

namespace App\Domains\Search\Services;

use App\Domains\Advertising\DTOs\AdDeliveryContext;
use App\Domains\Advertising\Models\AdCampaign;
use App\Domains\Advertising\Models\AdPlacement;
use App\Domains\Advertising\Services\AdEligibilityService;
use App\Domains\Directory\Enums\BusinessStatus;
use App\Domains\Search\DTOs\SearchHit;
use App\Domains\Search\DTOs\SearchQuery;
use App\Domains\Search\Models\SponsoredPlacement;
use Carbon\CarbonImmutable;

final class SponsoredPlacementSelector
{
    public function __construct(
        private readonly GeoSpatialHelper $geoHelper,
        private readonly ?AdEligibilityService $adEligibility = null,
    ) {}

    /**
     * Select eligible, clearly labelled sponsored placements for Page 1 only.
     * Strictly separated from organic ranking; never bypasses active publication or quality floor.
     *
     * @param  list<int>  $targetCategoryIds
     * @param  list<int>  $localityIds
     * @param  list<int>  $amenityIds
     * @param  list<int>  $paymentMethodIds
     * @return list<SearchHit>
     */
    public function selectForPage(
        SearchQuery $query,
        ?int $cityId,
        array $targetCategoryIds,
        array $localityIds,
        array $amenityIds,
        array $paymentMethodIds,
        ?float $anchorLat,
        ?float $anchorLng,
        ?float $effectiveRadiusKm,
        array $locales,
    ): array {
        if ($query->page !== 1 || $cityId === null) {
            return [];
        }

        $maxSlots = max(0, (int) config('search.sponsored.max_slots_page_1', 2));
        if ($maxSlots === 0) {
            return [];
        }

        $now = CarbonImmutable::now();
        $minCompleteness = (int) config('search.sponsored.min_completeness_score', 40);
        $minRatingFloor = (float) config('search.sponsored.min_rating_floor', 3.0);
        $minReviewsFloor = (int) config('search.sponsored.min_reviews_for_rating_floor', 5);
        $disclosure = (string) config('search.sponsored.disclosure_label', 'Sponsored');

        $placementsQuery = SponsoredPlacement::query()
            ->where('status', 'active')
            ->where('city_id', $cityId)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>=', $now)
            ->whereColumn('spent_minor', '<', 'budget_minor');

        if ($targetCategoryIds !== []) {
            $placementsQuery->where(function ($q) use ($targetCategoryIds): void {
                $q->whereIn('category_id', $targetCategoryIds)
                    ->orWhereNull('category_id');
            });
        }

        if ($localityIds !== []) {
            $placementsQuery->where(function ($q) use ($localityIds): void {
                $q->whereIn('locality_id', $localityIds)
                    ->orWhereNull('locality_id');
            });
        }

        $placements = $placementsQuery
            ->whereHas('business', function ($b) use (
                $query,
                $minCompleteness,
                $minRatingFloor,
                $minReviewsFloor,
                $targetCategoryIds,
                $localityIds,
                $amenityIds,
                $paymentMethodIds,
            ): void {
                // Never bypass active publication or moderation status
                $b->where('status', BusinessStatus::Active->value)
                    ->whereNull('deleted_at')
                    ->where('completeness_score', '>=', $minCompleteness)
                    ->where(function ($ratingFloor) use ($minRatingFloor, $minReviewsFloor): void {
                        $ratingFloor->where('review_count', '<', $minReviewsFloor)
                            ->orWhere('rating_avg', '>=', $minRatingFloor);
                    });

                if ($targetCategoryIds !== []) {
                    $b->where(function ($catQ) use ($targetCategoryIds): void {
                        $catQ->whereIn('primary_category_id', $targetCategoryIds)
                            ->orWhereHas('categories', static fn ($c) => $c->whereIn('categories.id', $targetCategoryIds));
                    });
                }

                if ($localityIds !== []) {
                    $b->whereIn('locality_id', $localityIds);
                }

                if ($query->verifiedOnly) {
                    $b->where('is_verified', true);
                }

                if ($query->minRating !== null) {
                    $b->where('rating_avg', '>=', $query->minRating);
                }

                foreach ($amenityIds as $amenityId) {
                    $b->whereHas('amenities', static fn ($aq) => $aq->where('amenities.id', $amenityId));
                }

                foreach ($paymentMethodIds as $pmId) {
                    $b->whereHas('paymentMethods', static fn ($pq) => $pq->where('payment_methods.id', $pmId));
                }
            })
            ->with([
                'business' => static function ($b) use ($locales): void {
                    $b->with([
                        'city.translations' => static fn ($q) => $q->whereIn('locale', $locales),
                        'locality.translations' => static fn ($q) => $q->whereIn('locale', $locales),
                        'primaryCategory.translations' => static fn ($q) => $q->whereIn('locale', $locales),
                        'hours',
                        'services' => static fn ($q) => $q->where('is_active', true),
                        'media' => static fn ($q) => $q->publicApproved(),
                    ]);
                },
            ])
            ->orderByDesc('bid_minor')
            ->orderBy('id')
            ->limit($maxSlots * 3)
            ->get();

        $hits = [];
        $seenBusinessIds = [];

        foreach ($placements as $placement) {
            $business = $placement->business;
            if ($business === null || isset($seenBusinessIds[$business->id])) {
                continue;
            }

            if ($query->openNow && ! $business->isOpenNow()) {
                continue;
            }

            $distanceKm = null;
            if ($anchorLat !== null && $anchorLng !== null) {
                $distanceKm = $this->geoHelper->distanceKm(
                    $anchorLat,
                    $anchorLng,
                    (float) $business->latitude,
                    (float) $business->longitude,
                );

                if ($effectiveRadiusKm !== null && $distanceKm > $effectiveRadiusKm) {
                    continue;
                }
            }

            $seenBusinessIds[$business->id] = true;
            $hits[] = new SearchHit(
                business: $business,
                distanceKm: $distanceKm,
                isSponsored: true,
                sponsorDisclosure: $disclosure,
                relevanceScore: 1.0,
            );

            if (count($hits) >= $maxSlots) {
                break;
            }
        }

        if (count($hits) < $maxSlots) {
            $hits = $this->appendEligibleFeaturedCampaignHits(
                hits: $hits,
                seenBusinessIds: $seenBusinessIds,
                maxSlots: $maxSlots,
                query: $query,
                cityId: $cityId,
                targetCategoryIds: $targetCategoryIds,
                localityIds: $localityIds,
                anchorLat: $anchorLat,
                anchorLng: $anchorLng,
                effectiveRadiusKm: $effectiveRadiusKm,
                locales: $locales,
                now: $now,
                minCompleteness: $minCompleteness,
                minRatingFloor: $minRatingFloor,
                minReviewsFloor: $minReviewsFloor,
                disclosure: $disclosure,
            );
        }

        return $hits;
    }

    /**
     * @param  list<SearchHit>  $hits
     * @param  array<int, bool>  $seenBusinessIds
     * @param  list<int>  $targetCategoryIds
     * @param  list<int>  $localityIds
     * @param  list<string>  $locales
     * @return list<SearchHit>
     */
    private function appendEligibleFeaturedCampaignHits(
        array $hits,
        array $seenBusinessIds,
        int $maxSlots,
        SearchQuery $query,
        int $cityId,
        array $targetCategoryIds,
        array $localityIds,
        ?float $anchorLat,
        ?float $anchorLng,
        ?float $effectiveRadiusKm,
        array $locales,
        CarbonImmutable $now,
        int $minCompleteness,
        float $minRatingFloor,
        int $minReviewsFloor,
        string $disclosure,
    ): array {
        $campaigns = AdCampaign::query()
            ->where('status', 'active')
            ->where('campaign_type', 'featured_listing')
            ->whereNull('deleted_at')
            ->with([
                'creatives' => static fn ($q) => $q->where('status', 'active'),
                'targetingRules',
                'businesses' => static function ($b) use ($cityId, $locales, $minCompleteness, $minRatingFloor, $minReviewsFloor, $targetCategoryIds, $localityIds, $query): void {
                    $b->where('status', BusinessStatus::Active->value)
                        ->whereNull('deleted_at')
                        ->where('city_id', $cityId)
                        ->where('completeness_score', '>=', $minCompleteness)
                        ->where(function ($rf) use ($minRatingFloor, $minReviewsFloor): void {
                            $rf->where('review_count', '<', $minReviewsFloor)
                                ->orWhere('rating_avg', '>=', $minRatingFloor);
                        });
                    if ($targetCategoryIds !== []) {
                        $b->where(function ($cq) use ($targetCategoryIds): void {
                            $cq->whereIn('primary_category_id', $targetCategoryIds)
                                ->orWhereHas('categories', static fn ($c) => $c->whereIn('categories.id', $targetCategoryIds));
                        });
                    }
                    if ($localityIds !== []) {
                        $b->whereIn('locality_id', $localityIds);
                    }
                    if ($query->verifiedOnly) {
                        $b->where('is_verified', true);
                    }
                    if ($query->minRating !== null) {
                        $b->where('rating_avg', '>=', $query->minRating);
                    }
                    $b->with([
                        'city.translations' => static fn ($q) => $q->whereIn('locale', $locales),
                        'locality.translations' => static fn ($q) => $q->whereIn('locale', $locales),
                        'primaryCategory.translations' => static fn ($q) => $q->whereIn('locale', $locales),
                        'hours',
                        'services' => static fn ($q) => $q->where('is_active', true),
                        'media' => static fn ($q) => $q->publicApproved(),
                    ]);
                },
            ])
            ->orderByDesc('priority')
            ->orderBy('id')
            ->limit($maxSlots * 3)
            ->get();

        $eligibility = $this->adEligibility ?? new AdEligibilityService();
        $virtualPlacement = new AdPlacement(['supports_category_targeting' => true, 'supports_location_targeting' => true, 'supports_domain_targeting' => true]);
        $context = new AdDeliveryContext(
            pageType: 'search',
            placementKeys: ['search.native_slot_1'],
            routePath: '/search',
            locale: $query->locale,
            cityId: $cityId,
            localityId: $localityIds[0] ?? null,
            categoryId: $targetCategoryIds[0] ?? null,
            categoryAncestorIds: $targetCategoryIds,
        );

        $seenOrgIds = [];

        foreach ($campaigns as $campaign) {
            if ($campaign->starts_at !== null && $campaign->starts_at->gt($now)) {
                continue;
            }
            if ($campaign->ends_at !== null && $campaign->ends_at->lte($now)) {
                continue;
            }
            if (! $eligibility->hasRemainingImpressionCap($campaign, $now)) {
                continue;
            }
            if (! $eligibility->matchesTargeting($campaign, $virtualPlacement, $context)) {
                continue;
            }
            if ($campaign->organization_id !== null && isset($seenOrgIds[$campaign->organization_id])) {
                continue;
            }

            foreach ($campaign->businesses as $business) {
                if (isset($seenBusinessIds[$business->id])) {
                    continue;
                }
                if ($query->openNow && ! $business->isOpenNow()) {
                    continue;
                }

                $distanceKm = null;
                if ($anchorLat !== null && $anchorLng !== null) {
                    $distanceKm = $this->geoHelper->distanceKm($anchorLat, $anchorLng, (float) $business->latitude, (float) $business->longitude);
                    if ($effectiveRadiusKm !== null && $distanceKm > $effectiveRadiusKm) {
                        continue;
                    }
                }

                $seenBusinessIds[$business->id] = true;
                if ($campaign->organization_id !== null) {
                    $seenOrgIds[$campaign->organization_id] = true;
                }

                $hits[] = new SearchHit(
                    business: $business,
                    distanceKm: $distanceKm,
                    isSponsored: true,
                    sponsorDisclosure: $disclosure,
                    relevanceScore: 1.0,
                );

                break; // Max 1 branch per campaign per page
            }

            if (count($hits) >= $maxSlots) {
                break;
            }
        }

        return $hits;
    }
}
