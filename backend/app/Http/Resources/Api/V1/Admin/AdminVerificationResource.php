<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin;

use App\Domains\Directory\Models\BusinessVerification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BusinessVerification
 */
final class AdminVerificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'business' => $this->business !== null ? [
                'public_id' => $this->business->public_id,
                'name' => $this->business->name,
                'slug' => $this->business->slug,
                'is_verified' => $this->business->is_verified,
                'is_trusted' => $this->business->is_trusted,
                'status' => $this->business->status->value,
                'city' => $this->business->city?->name,
            ] : null,
            'submitted_by' => $this->submittedBy !== null ? [
                'public_id' => $this->submittedBy->public_id,
                'name' => $this->submittedBy->name,
                'phone_e164' => $this->submittedBy->phone_e164,
            ] : null,
            'document_type' => $this->document_type->value,
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
