<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Api\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies live database role membership independently of Sanctum token abilities.
 */
final class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            return ApiResponse::error(
                code: 'UNAUTHENTICATED',
                message: 'Authentication is required to access this resource.',
                status: Response::HTTP_UNAUTHORIZED,
            );
        }

        foreach ($roles as $role) {
            if ($user->hasRole($role)) {
                return $next($request);
            }
        }

        return ApiResponse::error(
            code: 'FORBIDDEN',
            message: 'You do not have the required role to perform this action.',
            status: Response::HTTP_FORBIDDEN,
        );
    }
}
