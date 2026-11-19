<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Leads;

use App\Domains\Leads\Models\EnquiryRecipient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EnquiryRecipient
 */
final class OwnerLeadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'lead_status' => $this->lead_status->value,
            'delivery_channel' => $this->delivery_channel,
            'delivery_status' => $this->delivery_status,
            'delivery_timestamp' => $this->delivery_timestamp?->toIso8601String(),
            'is_read' => $this->is_read,
            'read_at' => $this->read_at?->toIso8601String(),
            'contacted_at' => $this->contacted_at?->toIso8601String(),
            'merchant_notes' => $this->merchant_notes,
            'assigned_user' => $this->assignedUser ? [
                'public_id' => $this->assignedUser->public_id,
                'name' => $this->assignedUser->name,
                'email' => $this->assignedUser->email,
            ] : null,
            'enquiry' => [
                'public_id' => $this->enquiry->public_id,
                'customer_name' => $this->enquiry->customer_name,
                'phone_e164' => $this->enquiry->phone_e164,
                'email' => $this->enquiry->email,
                'requirement' => $this->enquiry->requirement,
                'urgency' => $this->enquiry->urgency->value,
                'enquiry_type' => $this->enquiry_type ?? $this->enquiry->enquiry_type->value,
                'category' => [
                    'name' => $this->enquiry->category->name,
                    'slug' => $this->enquiry->category->slug,
                ],
                'city' => [
                    'name' => $this->enquiry->city->name,
                    'slug' => $this->enquiry->city->slug,
                ],
                'locality' => $this->enquiry->locality ? [
                    'name' => $this->enquiry->locality->name,
                    'slug' => $this->enquiry->locality->slug,
                ] : null,
                'created_at' => $this->enquiry->created_at?->toIso8601String(),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
