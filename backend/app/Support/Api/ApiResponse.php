<?php

declare(strict_types=1);

namespace App\Support\Api;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

final class ApiResponse
{
    public static function success(
        mixed $data = null,
        array $meta = [],
        int $status = Response::HTTP_OK,
        array $headers = [],
    ): JsonResponse {
        return response()->json([
            'status' => 'success',
            'data' => $data,
            'meta' => self::buildMeta($meta),
        ], $status, $headers);
    }

    public static function created(
        mixed $data = null,
        array $meta = [],
        array $headers = [],
    ): JsonResponse {
        return self::success($data, $meta, Response::HTTP_CREATED, $headers);
    }

    public static function noContent(array $headers = []): Response
    {
        return response()->noContent(Response::HTTP_NO_CONTENT, $headers);
    }

    public static function paginated(
        LengthAwarePaginator|CursorPaginator $paginator,
        mixed $items = null,
        array $meta = [],
    ): JsonResponse {
        $paginationMeta = $paginator instanceof LengthAwarePaginator
            ? [
                'type' => 'offset',
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'next_cursor' => null,
            ]
            : [
                'type' => 'cursor',
                'per_page' => $paginator->perPage(),
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'prev_cursor' => $paginator->previousCursor()?->encode(),
            ];

        return self::success(
            data: $items ?? $paginator->items(),
            meta: array_merge($meta, ['pagination' => $paginationMeta]),
        );
    }

    public static function error(
        string $code,
        string $message,
        int $status = Response::HTTP_BAD_REQUEST,
        array $details = [],
        array $meta = [],
        array $headers = [],
    ): JsonResponse {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return response()->json([
            'status' => 'error',
            'error' => $error,
            'meta' => self::buildMeta($meta),
        ], $status, $headers);
    }

    private static function buildMeta(array $extra = []): array
    {
        $request = request();
        $requestId = $request?->attributes->get('request_id');

        return array_merge([
            'request_id' => is_string($requestId) ? $requestId : null,
            'api_version' => (string) config('directory.api_version', 'v1'),
            'timestamp' => now()->utc()->toIso8601ZuluString(),
        ], $extra);
    }
}
