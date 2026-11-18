<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Api\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->status !== 'active') {
            return ApiResponse::error(
                code: 'ACCOUNT_SUSPENDED',
                message: 'Your account is currently suspended or inactive.',
                status: Response::HTTP_FORBIDDEN,
            );
        }

        return $next($request);
    }
}
