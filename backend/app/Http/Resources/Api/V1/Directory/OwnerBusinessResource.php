<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Directory;

use App\Domains\Directory\Models\Business;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Authenticated owner & authorized staff representation.
 * Never exposes internal integer database IDs.
 *
 * @mixin Business
 */
final class OwnerBusinessResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $actor = $request->user();
        $membership = $actor ? $this->activeMemberFor($actor) : null;

        return [
            'public_id' => $this->public_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'tagline' => $this->tagline,
            'description' => $this->description,
            'search_keywords' => $this->search_keywords,
            'year_established' => $this->year_established,
            'status' => $this->status->value,
            'is_claimed' => $this->is_claimed,
            'is_verified' => $this->is_verified,
            'verified_at' => $this->verified_at?->utc()->toIso8601ZuluString(),
            'is_trusted' => $this->is_trusted,
            'completeness_score' => $this->completeness_score,
            'primary_category' => $this->primaryCategory ? [
                'public_id' => $this->primaryCategory->public_id,
                'slug' => $this->primaryCategory->slug,
                'name' => $this->primaryCategory->name,
            ] : null,
            'categories' => $this->categories->map(static fn ($cat): array => [
                'public_id' => $cat->public_id,
                'slug' => $cat->slug,
                'name' => $cat->name,
                'is_primary' => (bool) $cat->pivot->is_primary,
            ])->values()->all(),
            'address' => [
                'line_1' => $this->address_line_1,
                'line_2' => $this->address_line_2,
                'landmark' => $this->landmark,
                'locality_slug' => $this->locality?->slug,
                'locality_name' => $this->locality?->name,
                'city_slug' => $this->city?->slug,
                'city_name' => $this->city?->name,
                'pincode' => $this->pincode,
                'latitude' => $this->latitude,
                'longitude' => $this->longitude,
            ],
            'contact' => [
                'primary_phone_e164' => $this->primary_phone_e164,
                'secondary_phone_e164' => $this->secondary_phone_e164,
                'whatsapp_e164' => $this->whatsapp_e164,
                'email' => $this->email,
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
            'services' => BusinessServiceResource::collection($this->services)->resolve($request),
            'amenities' => $this->amenities->pluck('slug')->values()->all(),
            'payment_methods' => $this->paymentMethods->pluck('slug')->values()->all(),
            'my_membership' => $actor ? [
                'is_owner' => $this->isOwnedBy($actor),
                'role' => $this->isOwnedBy($actor) ? 'owner' : ($membership?->role?->value ?? null),
                'capabilities' => $this->isOwnedBy($actor)
                    ? ['business.view', 'profile.edit', 'hours.edit', 'services.edit', 'leads.manage', 'reviews.reply', 'verification.submit', 'staff.manage']
                    : ($membership?->effectiveCapabilities() ?? []),
            ] : null,
            'created_at' => $this->created_at?->utc()->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->utc()->toIso8601ZuluString(),
        ];
    }
}
