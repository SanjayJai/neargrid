<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Directory;

use App\Domains\Directory\Models\BusinessMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BusinessMember
 */
final class BusinessMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'role' => $this->role->value,
            'status' => $this->status,
            'capabilities' => $this->effectiveCapabilities(),
            'user' => $this->user ? [
                'public_id' => $this->user->public_id,
                'name' => $this->user->name,
                'phone_e164' => $this->user->phone_e164,
            ] : null,
            'accepted_at' => $this->accepted_at?->utc()->toIso8601ZuluString(),
            'created_at' => $this->created_at?->utc()->toIso8601ZuluString(),
        ];
    }
}
