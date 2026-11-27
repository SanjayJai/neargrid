<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domains\Governance\Models\AuditLog;
use App\Domains\Governance\Services\AdminAnalyticsService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\AdminAnalyticsRequest;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class AdminAnalyticsController extends Controller
{
    public function __construct(
        private readonly AdminAnalyticsService $analytics,
    ) {}

    public function index(AdminAnalyticsRequest $request): JsonResponse
    {
        Gate::authorize('viewAdmin', AuditLog::class);

        $v = $request->validated();

        $payload = $this->analytics->overview(
            range: (string) ($v['range'] ?? '30d'),
            fromDate: isset($v['from']) ? (string) $v['from'] : null,
            toDate: isset($v['to']) ? (string) $v['to'] : null,
            citySlug: isset($v['city_slug']) ? (string) $v['city_slug'] : null,
        );

        return ApiResponse::success(
            data: $payload,
            status: Response::HTTP_OK,
            headers: [
                'Cache-Control' => 'private, no-store',
            ],
        );
    }
}
