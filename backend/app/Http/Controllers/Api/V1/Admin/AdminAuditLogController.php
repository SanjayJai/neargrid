<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domains\Governance\Models\AuditLog;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\AuditLogResource;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class AdminAuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAdmin', AuditLog::class);

        $query = AuditLog::query();

        if ($request->filled('actor_user_id')) {
            $query->where('actor_user_id', (int) $request->query('actor_user_id'));
        }

        if ($request->filled('actor_role')) {
            $query->where('actor_role', (string) $request->query('actor_role'));
        }

        if ($request->filled('action')) {
            $query->where('action', 'like', '%'.(string) $request->query('action').'%');
        }

        if ($request->filled('auditable_type')) {
            $query->where('auditable_type', (string) $request->query('auditable_type'));
        }

        if ($request->filled('from_date')) {
            $query->where('created_at', '>=', (string) $request->query('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->where('created_at', '<=', (string) $request->query('to_date'));
        }

        $logs = $query->orderByDesc('id')->paginate(25);

        return ApiResponse::paginated(
            $logs,
            AuditLogResource::collection($logs)->resolve($request)
        );
    }
}
