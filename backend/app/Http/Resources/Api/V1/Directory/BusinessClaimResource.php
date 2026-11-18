<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Directory;

use App\Domains\Directory\Models\BusinessClaim;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Never echoes private proof file paths or internal IDs.
 *
 * @mixin BusinessClaim
 */
final class BusinessClaimResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'status' => $this->status,
            'claim_notes' => $this->claim_notes,
            'review_notes' => $this->review_notes,
            'reviewed_at' => $this->reviewed_at?->utc()->toIso8601ZuluString(),
            'created_at' => $this->created_at?->utc()->toIso8601ZuluString(),
        ];
    }
}
