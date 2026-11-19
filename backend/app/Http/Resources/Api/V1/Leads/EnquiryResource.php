<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Leads;

use App\Domains\Leads\Models\Enquiry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Enquiry
 */
final class EnquiryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'customer_name' => $this->customer_name,
            'phone_e164' => $this->phone_e164,
            'email' => $this->email,
            'requirement' => $this->requirement,
            'urgency' => $this->urgency->value,
            'enquiry_type' => $this->enquiry_type->value,
            'status' => $this->status->value,
            'consent' => [
                'given' => $this->consent_given,
                'version' => $this->consent_version,
                'text' => $this->consent_text,
                'purpose' => $this->consent_purpose,
                'timestamp' => $this->consent_timestamp?->toIso8601String(),
            ],
            'target_business' => $this->targetBusiness ? [
                'public_id' => $this->targetBusiness->public_id,
                'name' => $this->targetBusiness->name,
                'slug' => $this->targetBusiness->slug,
            ] : null,
            'category' => [
                'public_id' => $this->category->public_id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ],
            'city' => [
                'name' => $this->city->name,
                'slug' => $this->city->slug,
            ],
            'locality' => $this->locality ? [
                'name' => $this->locality->name,
                'slug' => $this->locality->slug,
            ] : null,
            'recipients_count' => $this->recipients->count(),
            'recipients' => $this->recipients->map(fn ($r) => [
                'public_id' => $r->public_id,
                'business_name' => $r->business->name,
                'business_slug' => $r->business->slug,
                'delivery_status' => $r->delivery_status,
                'delivery_timestamp' => $r->delivery_timestamp->toIso8601String(),
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
