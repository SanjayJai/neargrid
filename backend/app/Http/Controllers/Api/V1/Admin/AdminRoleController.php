<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domains\Governance\Services\AuditLogger;
use App\Domains\Identity\Models\Permission;
use App\Domains\Identity\Models\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class AdminRoleController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function indexRoles(): JsonResponse
    {
        Gate::authorize('viewAdmin', User::class);

        $roles = Role::query()
            ->with('permissions:id,name,label,group_name')
            ->withCount('users')
            ->orderBy('id')
            ->get()
            ->map(static fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'label' => $role->label,
                'description' => $role->description,
                'users_count' => (int) ($role->users_count ?? 0),
                'permissions' => $role->permissions->pluck('name')->values()->all(),
            ])
            ->values()
            ->all();

        return ApiResponse::success($roles);
    }

    public function indexPermissions(): JsonResponse
    {
        Gate::authorize('viewAdmin', User::class);

        $permissions = Permission::query()
            ->withCount('roles')
            ->orderBy('group_name')
            ->orderBy('name')
            ->get()
            ->map(static fn (Permission $perm): array => [
                'id' => $perm->id,
                'name' => $perm->name,
                'label' => $perm->label,
                'group_name' => $perm->group_name,
                'roles_count' => (int) ($perm->roles_count ?? 0),
            ])
            ->values()
            ->all();

        return ApiResponse::success($permissions);
    }

    public function syncRolePermissions(Request $request, Role $role): JsonResponse
    {
        Gate::authorize('manageAdmin', User::class);

        $validated = $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        /** @var list<string> $requestedNames */
        $requestedNames = array_values(array_unique($validated['permissions']));

        /** @var User $actor */
        $actor = $request->user();
        $heldPermissions = $actor->roles()
            ->with('permissions:id,name')
            ->get()
            ->flatMap(static fn (Role $r) => $r->permissions->pluck('name'))
            ->unique()
            ->all();

        foreach ($requestedNames as $permName) {
            if (! in_array($permName, $heldPermissions, true)) {
                return ApiResponse::error(
                    'PERMISSION_ESCALATION_FORBIDDEN',
                    "You cannot grant permission '{$permName}' because your account does not possess it.",
                    403
                );
            }
        }

        if ($role->name === 'super_admin' && ! in_array('roles.manage', $requestedNames, true)) {
            return ApiResponse::error(
                'SUPER_ADMIN_PERMISSION_LOCKED',
                'Cannot remove roles.manage permission from super_admin role.',
                422
            );
        }

        $oldPermissions = $role->permissions()->pluck('name')->values()->all();
        $permissionIds = Permission::query()->whereIn('name', $requestedNames)->pluck('id')->all();

        DB::transaction(function () use ($actor, $role, $permissionIds, $oldPermissions, $requestedNames, $request): void {
            $role->permissions()->sync($permissionIds);

            $this->auditLogger->record(
                actor: $actor,
                action: 'role.permissions_updated',
                auditable: $role,
                oldValues: ['permissions' => $oldPermissions],
                newValues: ['permissions' => $requestedNames],
                actorRole: 'super_admin',
                ipAddress: $request->ip(),
            );
        });

        $role->load('permissions')->loadCount('users');

        return ApiResponse::success([
            'id' => $role->id,
            'name' => $role->name,
            'label' => $role->label,
            'permissions' => $role->permissions->pluck('name')->values()->all(),
        ], ['message' => "Permissions updated for role {$role->name}."]);
    }

    public function assignUserRole(Request $request, User $user): JsonResponse
    {
        Gate::authorize('assignRole', $user);

        $validated = $request->validate([
            'role' => ['required', 'string', 'exists:roles,name'],
        ]);

        $role = Role::query()->where('name', (string) $validated['role'])->firstOrFail();
        /** @var User $actor */
        $actor = $request->user();

        if ($user->status !== 'active') {
            return ApiResponse::error('USER_NOT_ACTIVE', 'Cannot assign roles to a non-active user.', 422);
        }

        if ((int) $actor->id === (int) $user->id && $role->name === 'super_admin' && ! $actor->hasRole('super_admin')) {
            return ApiResponse::error('SELF_ESCALATION_FORBIDDEN', 'Users cannot self-assign super_admin.', 403);
        }

        DB::transaction(function () use ($actor, $user, $role, $request): void {
            $user->roles()->syncWithoutDetaching([
                $role->id => ['assigned_by_user_id' => $actor->id],
            ]);

            $this->auditLogger->record(
                actor: $actor,
                action: 'user.role_assigned',
                auditable: $user,
                newValues: ['role' => $role->name],
                actorRole: 'super_admin',
                ipAddress: $request->ip(),
            );
        });

        return ApiResponse::success([
            'user_public_id' => $user->public_id,
            'roles' => $user->roles()->pluck('name')->values()->all(),
        ], ['message' => "Role {$role->name} assigned."]);
    }

    public function removeUserRole(Request $request, User $user, Role $role): JsonResponse
    {
        Gate::authorize('assignRole', $user);

        /** @var User $actor */
        $actor = $request->user();

        if ($role->name === 'super_admin' && (int) $actor->id === (int) $user->id) {
            return ApiResponse::error(
                'SELF_ROLE_REMOVAL_FORBIDDEN',
                'Super Admins cannot remove their own super_admin role.',
                422
            );
        }

        $blockedError = DB::transaction(function () use ($actor, $user, $role, $request): ?JsonResponse {
            if ($role->name === 'super_admin') {
                $activeSuperAdminIds = User::query()
                    ->where('status', 'active')
                    ->whereHas('roles', static fn ($q) => $q->where('name', 'super_admin'))
                    ->lockForUpdate()
                    ->pluck('id')
                    ->all();

                $otherActive = array_filter($activeSuperAdminIds, static fn ($id): bool => (int) $id !== (int) $user->id);
                if ($otherActive === []) {
                    return ApiResponse::error(
                        'LAST_SUPER_ADMIN_PROTECTED',
                        'Cannot remove super_admin from the last active Super Admin.',
                        422
                    );
                }
            }

            $user->roles()->detach($role->id);

            $this->auditLogger->record(
                actor: $actor,
                action: 'user.role_removed',
                auditable: $user,
                oldValues: ['role' => $role->name],
                actorRole: 'super_admin',
                ipAddress: $request->ip(),
            );

            return null;
        });

        if ($blockedError !== null) {
            return $blockedError;
        }

        return ApiResponse::success([
            'user_public_id' => $user->public_id,
            'roles' => $user->roles()->pluck('name')->values()->all(),
        ], ['message' => "Role {$role->name} removed."]);
    }
}
