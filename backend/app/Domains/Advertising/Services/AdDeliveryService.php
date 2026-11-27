<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Services;

use App\Domains\Advertising\DTOs\AdDeliveryContext;
use App\Domains\Advertising\Models\AdCampaign;
use App\Domains\Advertising\Models\AdPlacement;
use Carbon\CarbonImmutable;

final class AdDeliveryService
{
    public function __construct(
        private readonly AdEligibilityService $eligibility,
        private readonly AdTrackingService $tracking,
    ) {}

    /**
     * Deliver eligible ads keyed by placement key for the requested page context.
     *
     * @return array<string, array<string, mixed>>
     */
    public function deliver(AdDeliveryContext $context): array
    {
        if ($context->placementKeys === []) {
            return [];
        }

        $now = CarbonImmutable::now();

        $placements = $this->loadPlacements($context);
        if ($placements->isEmpty()) {
            return [];
        }

        $result = [];

        foreach ($placements as $placement) {
            $candidates = [];

            foreach ($placement->campaigns as $campaign) {
                if (! ($campaign->pivot->is_active ?? true)) {
                    continue;
                }

                if (! $this->eligibility->isCampaignEligible($campaign, $placement, $context, $now)) {
                    continue;
                }

                $creative = $this->eligibility->selectCreative($campaign, $context->locale);
                if ($creative === null) {
                    continue;
                }

                $candidates[] = [
                    'campaign' => $campaign,
                    'creative' => $creative,
                    'priority' => (int) $campaign->priority,
                    'weight' => (int) ($campaign->pivot->weight ?? 100),
                ];
            }

            usort($candidates, static function (array $a, array $b): int {
                return [$b['priority'], $b['weight'], $a['campaign']->id]
                    <=> [$a['priority'], $a['weight'], $b['campaign']->id];
            });

            $selected = array_slice($candidates, 0, max(1, (int) $placement->max_ads));
            if ($selected === []) {
                continue;
            }

            $ads = [];
            foreach ($selected as $item) {
                /** @var AdCampaign $campaign */
                $campaign = $item['campaign'];
                $creative = $item['creative'];

                $primaryBusiness = $campaign->businesses
                    ->first(static fn ($b): bool => (bool) ($b->pivot->is_primary ?? false))
                    ?? $campaign->businesses->first();

                $token = $this->tracking->mintToken([
                    'cid' => $campaign->public_id,
                    'crid' => $creative->public_id,
                    'pid' => $placement->public_id,
                    'dom' => $context->domainId,
                    'city' => $context->cityId,
                    'cat' => $context->categoryId,
                    'dev' => $context->device,
                    'aud' => $context->audience,
                ]);

                $ads[] = [
                    'creative_public_id' => $creative->public_id,
                    'title' => $creative->title,
                    'subtitle' => $creative->subtitle,
                    'cta_label' => $creative->cta_label,
                    'destination_url' => $creative->destination_url,
                    'image_url' => $creative->image_url,
                    'mobile_image_url' => $creative->mobile_image_url,
                    'alt_text' => $creative->alt_text ?? $creative->title,
                    'locale' => $creative->locale,
                    'disclosure_label' => 'Sponsored',
                    'tracking_token' => $token,
                    'business' => $primaryBusiness ? [
                        'public_id' => $primaryBusiness->public_id,
                        'name' => $primaryBusiness->name,
                        'slug' => $primaryBusiness->slug,
                    ] : null,
                ];
            }

            $result[$placement->key] = [
                'placement_key' => $placement->key,
                'render_format' => $placement->render_format,
                'slot_position' => $placement->slot_position,
                'width_px' => $placement->width_px,
                'height_px' => $placement->height_px,
                'max_ads' => $placement->max_ads,
                'ads' => $ads,
            ];
        }

        return $result;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, AdPlacement>
     */
    private function loadPlacements(AdDeliveryContext $context)
    {
        return AdPlacement::query()
            ->where('is_active', true)
            ->whereIn('key', $context->placementKeys)
            ->whereIn('page_type', [$context->pageType, 'global'])
            ->whereIn('device_scope', [$context->device, 'all'])
            ->whereIn('audience_scope', [$context->audience, 'all'])
            ->with([
                'campaigns' => static function ($q): void {
                    $q->where('ad_campaigns.status', 'active')
                        ->whereNull('ad_campaigns.deleted_at')
                        ->with([
                            'creatives' => static fn ($cq) => $cq->where('status', 'active'),
                            'targetingRules',
                            'businesses:id,public_id,name,slug,status',
                        ]);
                },
            ])
            ->orderBy('sort_order')
            ->get();
    }
}
