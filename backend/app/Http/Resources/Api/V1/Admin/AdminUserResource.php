<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
final class AdminUserResource extends JsonResource
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
            'phone_e164' => $this->phone_e164,
            'email' => $this->email,
            'status' => $this->status,
            'phone_verified_at' => $this->phone_verified_at?->toIso8601String(),
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'totp_enabled' => $this->totp_enabled_at !== null,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'roles' => $roles->pluck('name')->values()->all(),
            'permissions' => $permissions,
            'owned_businesses_count' => (int) ($this->owned_businesses_count ?? 0),
            'active_tokens_count' => (int) ($this->tokens_count ?? 0),
            'devices_count' => (int) ($this->devices_count ?? 0),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
