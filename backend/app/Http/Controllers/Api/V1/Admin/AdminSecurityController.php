<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domains\Governance\Models\AuditLog;
use App\Domains\Governance\Services\AuditLogger;
use App\Domains\Identity\Models\PersonalAccessToken;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\AuditLogResource;
use App\Models\User;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class AdminSecurityController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function sessions(Request $request): JsonResponse
    {
        Gate::authorize('viewAdmin', User::class);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);

        $query = PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->with(['tokenable:id,public_id,name,email,status'])
            ->where(static function ($q): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });

        if (! empty($validated['q'])) {
            $term = trim((string) $validated['q']);
            $query->where(static function ($q) use ($term): void {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhereHasMorph('tokenable', [User::class], static function ($uq) use ($term): void {
                        $uq->where('name', 'like', "%{$term}%")
                            ->orWhere('email', 'like', "%{$term}%")
                            ->orWhere('phone_e164', 'like', "%{$term}%");
                    });
            });
        }

        $tokens = $query->orderByDesc('last_used_at')->orderByDesc('id')->paginate((int) ($validated['per_page'] ?? 25));

        return ApiResponse::paginated(
            $tokens,
            collect($tokens->items())->map(static function (PersonalAccessToken $t): array {
                $user = $t->tokenable instanceof User ? $t->tokenable : null;

                return [
                    'public_id' => $t->public_id,
                    'name' => $t->name,
                    'device_public_id' => $t->device_public_id,
                    'abilities' => $t->abilities ?? [],
                    'last_used_at' => $t->last_used_at?->toIso8601String(),
                    'expires_at' => $t->expires_at?->toIso8601String(),
                    'created_at' => $t->created_at?->toIso8601String(),
                    'user' => $user ? [
                        'public_id' => $user->public_id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'status' => $user->status,
                    ] : null,
                ];
            })->values()->all()
        );
    }

    public function revokeSession(Request $request, string $tokenPublicId): JsonResponse
    {
        Gate::authorize('manageAdmin', User::class);

        $token = PersonalAccessToken::query()
            ->where('public_id', $tokenPublicId)
            ->firstOrFail();

        /** @var User $actor */
        $actor = $request->user();
        $targetUser = $token->tokenable instanceof User ? $token->tokenable : $actor;

        DB::transaction(function () use ($actor, $targetUser, $token, $request): void {
            $tokenPublic = $token->public_id;
            $tokenName = $token->name;
            $token->delete();

            $this->auditLogger->record(
                actor: $actor,
                action: 'security.session_revoked',
                auditable: $targetUser,
                newValues: ['token_public_id' => $tokenPublic, 'token_name' => $tokenName],
                actorRole: 'super_admin',
                ipAddress: $request->ip(),
            );
        });

        return ApiResponse::success(['revoked' => true], ['message' => 'Session revoked.']);
    }

    public function events(Request $request): JsonResponse
    {
        Gate::authorize('viewAdmin', AuditLog::class);

        $validated = $request->validate([
            'action' => ['nullable', 'string', 'max:80'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);

        $query = AuditLog::query()->where(static function ($q): void {
            $q->where('action', 'like', 'user.%')
                ->orWhere('action', 'like', 'role.%')
                ->orWhere('action', 'like', 'security.%')
                ->orWhere('action', 'like', 'auth.%');
        });

        if (! empty($validated['action'])) {
            $query->where('action', 'like', '%'.trim((string) $validated['action']).'%');
        }

        $logs = $query->orderByDesc('id')->paginate((int) ($validated['per_page'] ?? 25));

        return ApiResponse::paginated(
            $logs,
            AuditLogResource::collection($logs)->resolve($request)
        );
    }
}
