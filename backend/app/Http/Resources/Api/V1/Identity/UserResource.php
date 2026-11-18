<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Identity;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $roles = $this->relationLoaded('roles')
            ? $this->roles
            : $this->roles()->with('permissions')->get();

        $permissions = $roles
            ->flatMap(static fn ($role) => $role->relationLoaded('permissions')
                ? $role->permissions->pluck('name')
                : $role->permissions()->pluck('name'))
            ->unique()
            ->values()
            ->all();

        return [
            'public_id' => $this->public_id,
            'name' => $this->name,
            'email' => $this->email,
            'phone_e164' => $this->phone_e164,
            'phone_verified_at' => $this->phone_verified_at?->utc()->toIso8601ZuluString(),
            'avatar_url' => $this->avatar_url,
            'roles' => $roles->pluck('name')->values()->all(),
            'permissions' => $permissions,
            'created_at' => $this->created_at?->utc()->toIso8601ZuluString(),
        ];
    }
}
