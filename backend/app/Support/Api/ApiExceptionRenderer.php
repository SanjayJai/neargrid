<?php

declare(strict_types=1);

namespace App\Support\Api;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final class ApiExceptionRenderer
{
    public static function render(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        if ($exception instanceof ValidationException) {
            return ApiResponse::error(
                code: 'VALIDATION_FAILED',
                message: 'The given data was invalid.',
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
                details: $exception->errors(),
            );
        }

        if ($exception instanceof AuthenticationException) {
            return ApiResponse::error(
                code: 'UNAUTHENTICATED',
                message: 'Authentication is required to access this resource.',
                status: Response::HTTP_UNAUTHORIZED,
            );
        }

        if ($exception instanceof AuthorizationException || $exception instanceof AccessDeniedHttpException) {
            return ApiResponse::error(
                code: 'FORBIDDEN',
                message: 'You are not authorized to perform this action.',
                status: Response::HTTP_FORBIDDEN,
            );
        }

        if ($exception instanceof ModelNotFoundException || $exception instanceof NotFoundHttpException) {
            return ApiResponse::error(
                code: 'NOT_FOUND',
                message: 'The requested resource was not found.',
                status: Response::HTTP_NOT_FOUND,
            );
        }

        if ($exception instanceof MethodNotAllowedHttpException) {
            return ApiResponse::error(
                code: 'METHOD_NOT_ALLOWED',
                message: 'The HTTP method is not supported for this endpoint.',
                status: Response::HTTP_METHOD_NOT_ALLOWED,
                headers: $exception->getHeaders(),
            );
        }

        if ($exception instanceof ThrottleRequestsException) {
            return ApiResponse::error(
                code: 'RATE_LIMITED',
                message: 'Too many requests. Please slow down and try again later.',
                status: Response::HTTP_TOO_MANY_REQUESTS,
                headers: $exception->getHeaders(),
            );
        }

        if ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();

            return ApiResponse::error(
                code: self::statusToCode($status),
                message: $exception->getMessage() !== '' ? $exception->getMessage() : 'Request error.',
                status: $status,
                headers: $exception->getHeaders(),
            );
        }

        $message = (bool) config('app.debug')
            ? $exception->getMessage()
            : 'An unexpected internal error occurred.';

        return ApiResponse::error(
            code: 'INTERNAL_SERVER_ERROR',
            message: $message,
            status: Response::HTTP_INTERNAL_SERVER_ERROR,
        );
    }

    private static function statusToCode(int $status): string
    {
        return match ($status) {
            Response::HTTP_BAD_REQUEST => 'BAD_REQUEST',
            Response::HTTP_CONFLICT => 'CONFLICT',
            Response::HTTP_GONE => 'GONE',
            Response::HTTP_SERVICE_UNAVAILABLE => 'SERVICE_UNAVAILABLE',
            default => 'HTTP_ERROR',
        };
    }
}
