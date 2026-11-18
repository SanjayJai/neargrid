<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Identity;

use App\Domains\Identity\Models\UserDevice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin UserDevice
 */
final class UserDeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'platform' => $this->platform,
            'device_identifier' => $this->device_identifier,
            'has_push_token' => $this->fcm_token !== null && $this->fcm_token !== '',
            'app_version' => $this->app_version,
            'last_active_at' => $this->last_active_at?->utc()->toIso8601ZuluString(),
            'created_at' => $this->created_at?->utc()->toIso8601ZuluString(),
        ];
    }
}
