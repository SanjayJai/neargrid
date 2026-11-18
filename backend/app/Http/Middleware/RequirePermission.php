<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Api\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies live database role->permission membership independently of Sanctum token abilities.
 */
final class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if ($user === null) {
            return ApiResponse::error(
                code: 'UNAUTHENTICATED',
                message: 'Authentication is required to access this resource.',
                status: Response::HTTP_UNAUTHORIZED,
            );
        }

        if (! $user->hasPermission($permission)) {
            return ApiResponse::error(
                code: 'FORBIDDEN',
                message: 'You do not have permission to perform this action.',
                status: Response::HTTP_FORBIDDEN,
            );
        }

        return $next($request);
    }
}
