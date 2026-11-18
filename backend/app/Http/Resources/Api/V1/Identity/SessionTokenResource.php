<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Identity;

use App\Domains\Identity\Models\PersonalAccessToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PersonalAccessToken
 */
final class SessionTokenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currentToken = $request->user()?->currentAccessToken();
        $isCurrent = $currentToken instanceof PersonalAccessToken
            && $currentToken->id === $this->id;

        return [
            'session_id' => $this->public_id,
            'name' => $this->name,
            'device_public_id' => $this->device_public_id,
            'abilities' => $this->abilities ?? [],
            'last_used_at' => $this->last_used_at?->utc()->toIso8601ZuluString(),
            'expires_at' => $this->expires_at?->utc()->toIso8601ZuluString(),
            'created_at' => $this->created_at?->utc()->toIso8601ZuluString(),
            'is_current' => $isCurrent,
        ];
    }
}
