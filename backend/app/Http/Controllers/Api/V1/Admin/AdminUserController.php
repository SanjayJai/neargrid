<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domains\Governance\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdateUserStatusRequest;
use App\Http\Resources\Api\V1\Admin\AdminUserResource;
use App\Models\User;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

final class AdminUserController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAdmin', User::class);

        $query = User::query()->with('roles');

        if ($request->filled('phone_e164')) {
            $query->where('phone_e164', 'like', '%'.trim((string) $request->query('phone_e164')).'%');
        }

        if ($request->filled('email')) {
            $query->where('email', 'like', '%'.trim((string) $request->query('email')).'%');
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        if ($request->filled('role')) {
            $roleName = (string) $request->query('role');
            $query->whereHas('roles', static fn ($rq) => $rq->where('name', $roleName));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));
            $query->where(function ($sq) use ($search): void {
                $sq->where('name', 'like', "%{$search}%")
                    ->orWhere('phone_e164', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $query->orderByDesc('created_at')->paginate(25);

        return ApiResponse::paginated(
            $users,
            AdminUserResource::collection($users)->resolve($request)
        );
    }

    public function show(Request $request, User $user): JsonResponse
    {
        Gate::authorize('viewAdmin', User::class);

        $user->load('roles');

        return ApiResponse::success((new AdminUserResource($user))->resolve($request));
    }

    public function updateStatus(UpdateUserStatusRequest $request, User $user): JsonResponse
    {
        Gate::authorize('manageAdmin', User::class);

        $data = $request->validated();
        $newStatus = (string) $data['status'];
        $reason = isset($data['reason']) ? (string) $data['reason'] : null;
        $oldStatus = $user->status;

        $user->status = $newStatus;
        $user->save();

        if ($newStatus === 'suspended') {
            // Immediately terminate all active tokens and sessions on account suspension
            $user->tokens()->delete();

            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
        }

        $this->auditLogger->record(
            actor: $request->user(),
            action: 'user.status_updated',
            auditable: $user,
            oldValues: ['status' => $oldStatus],
            newValues: ['status' => $newStatus, 'reason' => $reason],
            actorRole: 'super_admin'
        );

        $user->load('roles');

        return ApiResponse::success(
            (new AdminUserResource($user))->resolve($request),
            ['message' => "User account status has been updated to {$newStatus}."]
        );
    }

    public function revokeSessions(Request $request, User $user): JsonResponse
    {
        Gate::authorize('manageAdmin', User::class);

        $user->tokens()->delete();

        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }

        $this->auditLogger->record(
            actor: $request->user(),
            action: 'user.sessions_revoked',
            auditable: $user,
            oldValues: [],
            newValues: ['revoked_by_admin_id' => $request->user()->id],
            actorRole: 'super_admin'
        );

        return ApiResponse::success([], [
            'message' => 'All active Sanctum tokens and sessions for this user have been revoked.',
        ]);
    }
}
