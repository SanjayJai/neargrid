<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SystemController extends Controller
{
    public function health(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
        ];

        $isHealthy = ! in_array('degraded', $checks, true);

        if (! $isHealthy) {
            return ApiResponse::error(
                code: 'SERVICE_UNAVAILABLE',
                message: 'One or more required dependencies are unavailable.',
                status: Response::HTTP_SERVICE_UNAVAILABLE,
                details: ['checks' => $checks],
            );
        }

        return ApiResponse::success([
            'service' => (string) config('app.name'),
            'status' => 'ok',
            'checks' => $checks,
        ]);
    }

    public function meta(): JsonResponse
    {
        return ApiResponse::success([
            'api_version' => (string) config('directory.api_version', 'v1'),
            'auth_scheme' => 'bearer',
            'supported_locales' => config('directory.supported_locales', ['en', 'ta']),
            'default_locale' => (string) config('directory.default_locale', 'en'),
            'default_currency' => (string) config('directory.default_currency', 'INR'),
            'default_timezone' => (string) config('directory.default_timezone', 'Asia/Kolkata'),
            'consent_version' => (string) config('privacy.consent_version', 'v1.0'),
            'flutter_compatible' => true,
        ]);
    }

    private function checkDatabase(): string
    {
        try {
            DB::select('SELECT 1');

            return 'ok';
        } catch (Throwable) {
            return 'degraded';
        }
    }

    private function checkCache(): string
    {
        $probeKey = 'health:probe:'.uniqid('', true);

        try {
            Cache::put($probeKey, 'ok', 10);
            $value = Cache::get($probeKey);
            Cache::forget($probeKey);

            return $value === 'ok' ? 'ok' : 'degraded';
        } catch (Throwable) {
            return 'degraded';
        }
    }
}
