<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domains\Governance\Models\AuditLog;
use App\Domains\Governance\Services\AuditLogger;
use App\Domains\Identity\Models\AccountErasureRequest;
use App\Domains\Identity\Models\PersonalAccessToken;
use App\Domains\Identity\Models\UserDevice;
use App\Domains\Leads\Models\Enquiry;
use App\Domains\Notifications\Notifications\AccountSecurityNotification;
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
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class AdminUserController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAdmin', User::class);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'phone_e164' => ['nullable', 'string', 'max:24'],
            'email' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', Rule::in(['active', 'suspended', 'erased'])],
            'role' => ['nullable', 'string', 'max:60'],
            'verified' => ['nullable', 'string', Rule::in(['phone', 'email', 'unverified'])],
            'sort_by' => ['nullable', 'string', Rule::in(['created_at', 'name', 'last_login_at', 'status'])],
            'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);

        $query = User::query()
            ->with('roles.permissions')
            ->withCount(['ownedBusinesses', 'tokens', 'devices']);

        if (! empty($validated['phone_e164'])) {
            $query->where('phone_e164', 'like', '%'.trim((string) $validated['phone_e164']).'%');
        }

        if (! empty($validated['email'])) {
            $query->where('email', 'like', '%'.trim((string) $validated['email']).'%');
        }

        if (! empty($validated['status'])) {
            $query->where('status', (string) $validated['status']);
        }

        if (! empty($validated['role'])) {
            $roleName = (string) $validated['role'];
            $query->whereHas('roles', static fn ($rq) => $rq->where('name', $roleName));
        }

        if (! empty($validated['verified'])) {
            if ($validated['verified'] === 'phone') {
                $query->whereNotNull('phone_verified_at');
            } elseif ($validated['verified'] === 'email') {
                $query->whereNotNull('email_verified_at');
            } elseif ($validated['verified'] === 'unverified') {
                $query->whereNull('phone_verified_at')->whereNull('email_verified_at');
            }
        }

        if (! empty($validated['search'])) {
            $search = trim((string) $validated['search']);
            $query->where(function ($sq) use ($search): void {
                $sq->where('name', 'like', "%{$search}%")
                    ->orWhere('phone_e164', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $sortBy = (string) ($validated['sort_by'] ?? 'created_at');
        $sortDir = (string) ($validated['sort_dir'] ?? 'desc');
        $perPage = (int) ($validated['per_page'] ?? 25);

        $users = $query->orderBy($sortBy, $sortDir)->paginate($perPage);

        return ApiResponse::paginated(
            $users,
            AdminUserResource::collection($users)->resolve($request)
        );
    }

    public function show(Request $request, User $user): JsonResponse
    {
        Gate::authorize('viewAdmin', User::class);

        $user->load('roles.permissions')->loadCount(['ownedBusinesses', 'tokens', 'devices']);

        $sessions = PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $user->id)
            ->orderByDesc('last_used_at')
            ->limit(15)
            ->get(['public_id', 'name', 'device_public_id', 'abilities', 'last_used_at', 'expires_at', 'created_at'])
            ->map(static fn (PersonalAccessToken $t): array => [
                'public_id' => $t->public_id,
                'name' => $t->name,
                'device_public_id' => $t->device_public_id,
                'abilities' => $t->abilities ?? [],
                'last_used_at' => $t->last_used_at?->toIso8601String(),
                'expires_at' => $t->expires_at?->toIso8601String(),
                'created_at' => $t->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $devices = UserDevice::query()
            ->where('user_id', $user->id)
            ->orderByDesc('last_active_at')
            ->limit(15)
            ->get(['public_id', 'platform', 'app_version', 'last_active_at', 'created_at'])
            ->map(static fn (UserDevice $d): array => [
                'public_id' => $d->public_id,
                'platform' => $d->platform,
                'app_version' => $d->app_version,
                'last_active_at' => $d->last_active_at?->toIso8601String(),
                'created_at' => $d->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $securityLogs = AuditLog::query()
            ->where('auditable_type', User::class)
            ->where('auditable_id', $user->id)
            ->orderByDesc('id')
            ->limit(15)
            ->get(['id', 'actor_role', 'action', 'created_at'])
            ->map(static fn (AuditLog $log): array => [
                'id' => $log->id,
                'actor_role' => $log->actor_role,
                'action' => $log->action,
                'created_at' => $log->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $erasureRequests = AccountErasureRequest::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(static fn (AccountErasureRequest $er): array => [
                'public_id' => $er->public_id,
                'status' => $er->status,
                'reason' => $er->reason,
                'decision_notes' => $er->decision_notes,
                'retention_snapshot' => $er->retention_snapshot,
                'reviewed_at' => $er->reviewed_at?->toIso8601String(),
                'anonymized_at' => $er->anonymized_at?->toIso8601String(),
                'created_at' => $er->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return ApiResponse::success([
            'user' => (new AdminUserResource($user))->resolve($request),
            'sessions' => $sessions,
            'devices' => $devices,
            'security_logs' => $securityLogs,
            'erasure_requests' => $erasureRequests,
        ]);
    }

    public function updateStatus(UpdateUserStatusRequest $request, User $user): JsonResponse
    {
        Gate::authorize('manageAdmin', User::class);

        /** @var User $actor */
        $actor = $request->user();
        $data = $request->validated();
        $newStatus = (string) $data['status'];
        $reason = isset($data['reason']) ? (string) $data['reason'] : null;
        $oldStatus = $user->status;

        if ($oldStatus === 'erased') {
            return ApiResponse::error('USER_ERASED', 'An anonymized/erased account cannot be reactivated.', 422);
        }

        if ($newStatus === 'suspended' && (int) $actor->id === (int) $user->id) {
            return ApiResponse::error('SELF_SUSPENSION_FORBIDDEN', 'Administrators cannot suspend their own account.', 422);
        }

        $blockedError = DB::transaction(function () use ($actor, $user, $oldStatus, $newStatus, $reason, $request): ?JsonResponse {
            if ($newStatus === 'suspended' && $user->hasRole('super_admin')) {
                $activeSuperAdminIds = User::query()
                    ->where('status', 'active')
                    ->whereHas('roles', static fn ($q) => $q->where('name', 'super_admin'))
                    ->lockForUpdate()
                    ->pluck('id')
                    ->all();

                $otherActive = array_filter($activeSuperAdminIds, static fn ($id): bool => (int) $id !== (int) $user->id);
                if ($otherActive === []) {
                    return ApiResponse::error('LAST_SUPER_ADMIN_PROTECTED', 'Cannot suspend the last active Super Admin account.', 422);
                }
            }

            $user->status = $newStatus;
            $user->save();

            if ($newStatus === 'suspended') {
                $user->tokens()->delete();

                if (Schema::hasTable('sessions')) {
                    DB::table('sessions')->where('user_id', $user->id)->delete();
                }
            }

            $this->auditLogger->record(
                actor: $actor,
                action: 'user.status_updated',
                auditable: $user,
                oldValues: ['status' => $oldStatus],
                newValues: ['status' => $newStatus, 'reason' => $reason],
                actorRole: 'super_admin',
                ipAddress: $request->ip(),
            );

            return null;
        });

        if ($blockedError !== null) {
            return $blockedError;
        }

        if ($oldStatus !== $newStatus) {
            $title = $newStatus === 'suspended' ? 'Account Suspended' : 'Account Reactivated';
            $msg = $newStatus === 'suspended'
                ? 'Your account has been suspended by platform administration. Please contact support.'
                : 'Your account has been reactivated. You now have full platform access.';

            $user->notify(new AccountSecurityNotification(
                eventType: 'status_updated',
                title: $title,
                message: $msg,
                uniqueEventId: 'status_'.$newStatus.'_'.now()->timestamp,
                actionUrl: '/dashboard/settings',
                extraMetadata: ['status' => $newStatus]
            ));
        }

        $user->load('roles.permissions')->loadCount(['ownedBusinesses', 'tokens', 'devices']);

        return ApiResponse::success(
            (new AdminUserResource($user))->resolve($request),
            ['message' => "User account status has been updated to {$newStatus}."]
        );
    }

    public function verifyAccount(Request $request, User $user): JsonResponse
    {
        Gate::authorize('manageAdmin', User::class);

        $validated = $request->validate([
            'channel' => ['required', 'string', Rule::in(['phone', 'email', 'both'])],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $channel = (string) $validated['channel'];

        DB::transaction(function () use ($actor, $user, $channel, $request): void {
            if (($channel === 'phone' || $channel === 'both') && $user->phone_verified_at === null) {
                $user->phone_verified_at = now();
            }
            if (($channel === 'email' || $channel === 'both') && $user->email !== null && $user->email_verified_at === null) {
                $user->email_verified_at = now();
            }
            $user->save();

            $this->auditLogger->record(
                actor: $actor,
                action: 'user.verified_by_admin',
                auditable: $user,
                newValues: ['channel' => $channel],
                actorRole: 'super_admin',
                ipAddress: $request->ip(),
            );
        });

        $user->load('roles.permissions')->loadCount(['ownedBusinesses', 'tokens', 'devices']);

        return ApiResponse::success(
            (new AdminUserResource($user))->resolve($request),
            ['message' => 'User verification updated.']
        );
    }

    public function revokeSessions(Request $request, User $user): JsonResponse
    {
        Gate::authorize('manageAdmin', User::class);

        /** @var User $actor */
        $actor = $request->user();

        DB::transaction(function () use ($actor, $user, $request): void {
            $user->tokens()->delete();

            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }

            $this->auditLogger->record(
                actor: $actor,
                action: 'user.sessions_revoked',
                auditable: $user,
                oldValues: [],
                newValues: ['revoked_by_admin_public_id' => $actor->public_id],
                actorRole: 'super_admin',
                ipAddress: $request->ip(),
            );
        });

        $user->notify(new AccountSecurityNotification(
            eventType: 'session_revocation',
            title: 'Active Sessions Revoked',
            message: 'An administrator or security protocol has revoked all active sessions for your account.',
            uniqueEventId: 'revoke_'.now()->timestamp.'_'.Str::random(6),
            actionUrl: '/dashboard/settings',
            extraMetadata: ['revoked_by_admin' => true]
        ));

        return ApiResponse::success([], [
            'message' => 'All active Sanctum tokens and sessions for this user have been revoked.',
        ]);
    }

    public function indexErasureRequests(Request $request): JsonResponse
    {
        Gate::authorize('viewAdmin', User::class);

        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(['pending', 'approved', 'rejected'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);

        $query = AccountErasureRequest::query()
            ->with(['user:id,public_id,name,email,status', 'requestedBy:id,public_id,name', 'reviewedBy:id,public_id,name']);

        if (! empty($validated['status'])) {
            $query->where('status', (string) $validated['status']);
        }

        $items = $query->orderByDesc('id')->paginate((int) ($validated['per_page'] ?? 20));

        return ApiResponse::paginated(
            $items,
            collect($items->items())->map(static fn (AccountErasureRequest $er): array => [
                'public_id' => $er->public_id,
                'status' => $er->status,
                'reason' => $er->reason,
                'decision_notes' => $er->decision_notes,
                'retention_snapshot' => $er->retention_snapshot,
                'reviewed_at' => $er->reviewed_at?->toIso8601String(),
                'anonymized_at' => $er->anonymized_at?->toIso8601String(),
                'created_at' => $er->created_at?->toIso8601String(),
                'user' => $er->user ? [
                    'public_id' => $er->user->public_id,
                    'name' => $er->user->name,
                    'email' => $er->user->email,
                    'status' => $er->user->status,
                ] : null,
                'reviewed_by' => $er->reviewedBy ? [
                    'public_id' => $er->reviewedBy->public_id,
                    'name' => $er->reviewedBy->name,
                ] : null,
            ])->values()->all()
        );
    }

    public function requestErasure(Request $request, User $user): JsonResponse
    {
        Gate::authorize('manageAdmin', User::class);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        if ($user->status === 'erased') {
            return ApiResponse::error('USER_ALREADY_ERASED', 'This account has already been anonymized.', 422);
        }

        if ($user->hasRole('super_admin')) {
            return ApiResponse::error('SUPER_ADMIN_ERASURE_FORBIDDEN', 'Super Admin accounts cannot be queued for erasure while holding super_admin role.', 422);
        }

        $existingPending = AccountErasureRequest::query()
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->exists();

        if ($existingPending) {
            return ApiResponse::error('ERASURE_ALREADY_PENDING', 'An erasure request is already pending for this user.', 409);
        }

        $snapshot = [
            'owned_businesses' => $user->ownedBusinesses()->count(),
            'active_businesses' => $user->ownedBusinesses()->whereIn('status', ['active', 'pending_review'])->count(),
            'open_enquiries' => Enquiry::query()->where('user_id', $user->id)->whereIn('status', ['pending_otp', 'active'])->count(),
            'business_memberships' => $user->businessMemberships()->count(),
        ];

        /** @var User $actor */
        $actor = $request->user();

        $erasure = DB::transaction(function () use ($actor, $user, $validated, $snapshot, $request): AccountErasureRequest {
            $record = AccountErasureRequest::query()->create([
                'user_id' => $user->id,
                'requested_by_user_id' => $actor->id,
                'status' => 'pending',
                'reason' => trim((string) $validated['reason']),
                'retention_snapshot' => $snapshot,
            ]);

            $this->auditLogger->record(
                actor: $actor,
                action: 'user.erasure_requested',
                auditable: $user,
                newValues: ['erasure_public_id' => $record->public_id, 'retention_snapshot' => $snapshot],
                actorRole: 'super_admin',
                ipAddress: $request->ip(),
            );

            return $record;
        });

        return ApiResponse::created([
            'public_id' => $erasure->public_id,
            'status' => $erasure->status,
            'retention_snapshot' => $erasure->retention_snapshot,
        ], ['message' => 'Account erasure request queued for retention review.']);
    }

    public function reviewErasureRequest(Request $request, AccountErasureRequest $erasureRequest): JsonResponse
    {
        Gate::authorize('manageAdmin', User::class);

        $validated = $request->validate([
            'decision' => ['required', 'string', Rule::in(['approved', 'rejected'])],
            'decision_notes' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $decision = (string) $validated['decision'];

        $reviewOutcome = DB::transaction(function () use ($actor, $erasureRequest, $decision, $validated, $request): array {
            $lockedRequest = AccountErasureRequest::query()
                ->whereKey($erasureRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRequest->status !== 'pending') {
                return ['error' => ApiResponse::error('ERASURE_ALREADY_REVIEWED', 'This erasure request has already been processed.', 422)];
            }

            $user = $lockedRequest->user()->lockForUpdate()->firstOrFail();

            if ($decision === 'approved') {
                if ($user->hasRole('super_admin')) {
                    return ['error' => ApiResponse::error('SUPER_ADMIN_ERASURE_FORBIDDEN', 'Remove super_admin role before approving erasure.', 422)];
                }

                $activeBusinesses = $user->ownedBusinesses()->whereIn('status', ['active', 'pending_review'])->count();
                $openEnquiries = Enquiry::query()->where('user_id', $user->id)->whereIn('status', ['pending_otp', 'active'])->count();
                if ($activeBusinesses > 0 || $openEnquiries > 0) {
                    return ['error' => ApiResponse::error(
                        'RETENTION_DEPENDENCY_BLOCKED',
                        'Cannot anonymize account while user owns active listings or open enquiries.',
                        422
                    )];
                }
            }

            $lockedRequest->status = $decision;
            $lockedRequest->reviewed_by_admin_id = $actor->id;
            $lockedRequest->decision_notes = $validated['decision_notes'] ?? null;
            $lockedRequest->reviewed_at = now();

            if ($decision === 'approved') {
                $user->tokens()->delete();
                $user->devices()->delete();
                if (Schema::hasTable('sessions')) {
                    DB::table('sessions')->where('user_id', $user->id)->delete();
                }

                $user->name = 'Deleted User';
                $user->email = null;
                $user->phone_e164 = 'erased_'.$user->id;
                $user->avatar_url = null;
                $user->status = 'erased';
                $user->save();
                $user->delete();

                $lockedRequest->anonymized_at = now();
            }

            $lockedRequest->save();

            $this->auditLogger->record(
                actor: $actor,
                action: $decision === 'approved' ? 'user.erasure_approved' : 'user.erasure_rejected',
                auditable: $user,
                newValues: [
                    'erasure_public_id' => $lockedRequest->public_id,
                    'decision' => $decision,
                ],
                actorRole: 'super_admin',
                ipAddress: $request->ip(),
            );

            return ['record' => $lockedRequest];
        });

        if (isset($reviewOutcome['error'])) {
            return $reviewOutcome['error'];
        }

        /** @var AccountErasureRequest $processed */
        $processed = $reviewOutcome['record'];

        return ApiResponse::success([
            'public_id' => $processed->public_id,
            'status' => $processed->status,
            'anonymized_at' => $processed->anonymized_at?->toIso8601String(),
        ], ['message' => "Account erasure request {$decision}."]);
    }
}
