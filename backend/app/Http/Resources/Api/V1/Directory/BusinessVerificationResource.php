<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Directory;

use App\Domains\Directory\Models\BusinessVerification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Never echoes encrypted document numbers or private S3 file paths.
 *
 * @mixin BusinessVerification
 */
final class BusinessVerificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'document_type' => $this->document_type->value,
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'reviewed_at' => $this->reviewed_at?->utc()->toIso8601ZuluString(),
            'created_at' => $this->created_at?->utc()->toIso8601ZuluString(),
        ];
    }
}
