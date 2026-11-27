<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Models\BusinessVerification;
use App\Domains\Domains\Models\Domain;
use App\Domains\Governance\Models\AuditLog;
use App\Domains\Leads\Models\Enquiry;
use App\Domains\Reviews\Models\Review;
use App\Domains\Taxonomy\Models\Category;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class AdminBootstrapController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        Gate::authorize('viewAdmin', AuditLog::class);

        /** @var User $actor */
        $actor = $request->user();
        $actor->loadMissing('roles.permissions');

        $recentLogs = AuditLog::query()
            ->orderByDesc('id')
            ->limit(8)
            ->get(['id', 'actor_role', 'action', 'auditable_type', 'auditable_id', 'created_at']);

        return ApiResponse::success([
            'actor' => [
                'public_id' => $actor->public_id,
                'name' => $actor->name,
                'email' => $actor->email,
                'roles' => $actor->roles->pluck('name')->values()->all(),
                'permissions' => $actor->roles
                    ->flatMap(static fn ($role) => $role->permissions->pluck('name'))
                    ->unique()
                    ->values()
                    ->all(),
            ],
            'module_counts' => [
                'businesses_total' => Business::query()->count(),
                'pending_verifications' => BusinessVerification::query()->where('status', 'pending')->count(),
                'pending_reviews' => Review::query()->where('moderation_status', 'pending')->count(),
                'open_enquiries' => Enquiry::query()->whereIn('status', ['pending_otp', 'active'])->count(),
                'domains_total' => Domain::query()->count(),
                'categories_total' => Category::query()->count(),
                'users_total' => User::query()->count(),
            ],
            'recent_activity' => $recentLogs->map(static fn (AuditLog $log): array => [
                'id' => $log->id,
                'actor_role' => $log->actor_role,
                'action' => $log->action,
                'auditable_type' => $log->auditable_type,
                'auditable_id' => $log->auditable_id,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->values()->all(),
            'capabilities' => [
                'navigation_search' => true,
                'record_search' => false,
            ],
        ]);
    }
}
