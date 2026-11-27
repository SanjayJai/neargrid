<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Directory;

use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Services\ProfileSectionResolverService;
use App\Domains\Media\Models\BusinessMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public storefront representation.
 * Strictly strips owner identity, internal database IDs, staff lists,
 * KYC verification records, claims, and unmasked private phone numbers.
 *
 * @mixin Business
 */
final class PublicBusinessResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = (string) $request->query('locale', 'en');
        $dynamicProfile = app(ProfileSectionResolverService::class)->resolve($this->resource, $locale);

        return [
            'public_id' => $this->public_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'tagline' => $this->tagline,
            'description' => $this->description,
            'year_established' => $this->year_established,
            'is_claimed' => $this->is_claimed,
            'is_verified' => $this->is_verified,
            'is_trusted' => $this->is_trusted,
            'rating_avg' => $this->rating_avg,
            'review_count' => $this->review_count,
            'photo_count' => $this->photo_count,
            'completeness_score' => $this->completeness_score,
            'is_open_now' => $this->isOpenNow(),
            'primary_category' => $this->primaryCategory ? [
                'public_id' => $this->primaryCategory->public_id,
                'slug' => $this->primaryCategory->slug,
                'name' => $this->primaryCategory->translatedName($locale),
                'schema_org_type' => $this->primaryCategory->schema_org_type,
            ] : null,
            'categories' => $this->categories->map(static fn ($cat): array => [
                'public_id' => $cat->public_id,
                'slug' => $cat->slug,
                'name' => $cat->translatedName($locale),
                'is_primary' => (bool) $cat->pivot->is_primary,
            ])->values()->all(),
            'address' => [
                'line_1' => $this->address_line_1,
                'line_2' => $this->address_line_2,
                'landmark' => $this->landmark,
                'locality' => $this->locality ? [
                    'slug' => $this->locality->slug,
                    'name' => $this->locality->translatedName($locale),
                ] : null,
                'city' => $this->city ? [
                    'slug' => $this->city->slug,
                    'name' => $this->city->translatedName($locale),
                ] : null,
                'pincode' => $this->pincode,
                'coordinates' => [
                    'lat' => $this->latitude,
                    'lng' => $this->longitude,
                ],
            ],
            'contact' => [
                'phone_masked' => $this->maskedPhone(),
                'has_whatsapp' => $this->whatsapp_e164 !== null && $this->whatsapp_e164 !== '',
                'website_url' => $this->website_url,
            ],
            'hours' => $this->hours->map(static fn ($slot): array => [
                'day_of_week' => $slot->day_of_week,
                'slot_index' => $slot->slot_index,
                'open_time' => $slot->open_time,
                'close_time' => $slot->close_time,
                'is_24_hours' => $slot->is_24_hours,
                'is_closed' => $slot->is_closed,
            ])->values()->all(),
            'services' => BusinessServiceResource::collection(
                $this->services->where('is_active', true)->values()
            )->resolve($request),
            'amenities' => $this->amenities->map(static fn ($a): array => [
                'slug' => $a->slug,
                'name' => $a->name,
                'icon_name' => $a->icon_name,
            ])->values()->all(),
            'payment_methods' => $this->paymentMethods->map(static fn ($pm): array => [
                'slug' => $pm->slug,
                'name' => $pm->name,
            ])->values()->all(),
            'faqs' => $this->faqs->where('is_active', true)->map(static fn ($faq): array => [
                'question' => $faq->question,
                'answer' => $faq->answer,
            ])->values()->all(),
            'media' => $this->media
                ->filter(static fn (BusinessMedia $item): bool => $item->isPubliclyDeliverable())
                ->map(static fn (BusinessMedia $item): array => [
                    'public_id' => $item->public_id,
                    'media_type' => $item->media_type->value,
                    'alt_text' => $item->alt_text,
                    'width' => $item->width,
                    'height' => $item->height,
                    'sort_order' => $item->sort_order,
                    'urls' => $item->publicUrls(),
                ])
                ->values()
                ->all(),
            'profile_sections' => $dynamicProfile['profile_sections'],
            'attributes' => $dynamicProfile['attributes'],
        ];
    }
}
