<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Search;

use App\Domains\Media\Models\BusinessMedia;
use App\Domains\Search\DTOs\SearchHit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Privacy-safe public search result card.
 * Strictly omits internal database IDs, owner details, unmasked phone numbers,
 * raw storage keys, unpublished media, and all KYC verification data.
 *
 * @property SearchHit $resource
 */
final class SearchBusinessHitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SearchHit $hit */
        $hit = $this->resource;
        $business = $hit->business;
        $locale = (string) $request->query('locale', 'en');

        $localityName = $business->locality?->translatedName($locale);
        $cityName = $business->city?->translatedName($locale);

        $addressParts = array_filter([
            $business->address_line_1,
            $localityName,
            $business->pincode,
        ]);

        $mediaPreview = $business->relationLoaded('media')
            ? $business->media
                ->filter(static fn (BusinessMedia $item): bool => $item->isPubliclyDeliverable())
                ->take(3)
                ->map(static fn (BusinessMedia $item): array => [
                    'public_id' => $item->public_id,
                    'media_type' => $item->media_type->value,
                    'alt_text' => $item->alt_text,
                    'urls' => $item->publicUrls(),
                ])
                ->values()
                ->all()
            : [];

        $servicesPreview = $business->relationLoaded('services')
            ? $business->services
                ->where('is_active', true)
                ->take(4)
                ->pluck('name')
                ->values()
                ->all()
            : [];

        return [
            'public_id' => $business->public_id,
            'name' => $business->name,
            'slug' => $business->slug,
            'tagline' => $business->tagline,
            'city_slug' => $business->city?->slug,
            'city_name' => $cityName,
            'locality_slug' => $business->locality?->slug,
            'locality_name' => $localityName,
            'primary_category' => $business->primaryCategory ? [
                'public_id' => $business->primaryCategory->public_id,
                'slug' => $business->primaryCategory->slug,
                'name' => $business->primaryCategory->translatedName($locale),
            ] : null,
            'is_sponsored' => $hit->isSponsored,
            'sponsor_disclosure' => $hit->sponsorDisclosure,
            'is_verified' => $business->is_verified,
            'is_trusted' => $business->is_trusted,
            'is_claimed' => $business->is_claimed,
            'rating_avg' => $business->rating_avg,
            'review_count' => $business->review_count,
            'completeness_score' => $business->completeness_score,
            'distance_km' => $hit->distanceKm,
            'is_open_now' => $business->isOpenNow(),
            'address_short' => implode(', ', $addressParts),
            'phone_masked' => $business->maskedPhone(),
            'has_whatsapp' => $business->whatsapp_e164 !== null && $business->whatsapp_e164 !== '',
            'services_preview' => $servicesPreview,
            'media_preview' => $mediaPreview,
        ];
    }
}
