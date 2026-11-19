<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Providers\AppServiceProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

final class ApiFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_returns_standard_envelope_and_security_headers(): void
    {
        $response = $this->get('/api/v1/health');

        $response
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(self)')
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.checks.database', 'ok')
            ->assertJsonPath('data.checks.cache', 'ok')
            ->assertJsonPath('meta.api_version', 'v1')
            ->assertJsonStructure([
                'status',
                'data' => ['service', 'status', 'checks' => ['database', 'cache']],
                'meta' => ['request_id', 'api_version', 'timestamp'],
            ]);

        $this->assertNotEmpty($response->headers->get('X-Request-ID'));
        $this->assertSame(
            $response->headers->get('X-Request-ID'),
            $response->json('meta.request_id')
        );
    }

    public function test_health_endpoint_returns_503_when_dependency_fails(): void
    {
        Cache::shouldReceive('put')
            ->once()
            ->andThrow(new RuntimeException('Redis unreachable'));

        $response = $this->get('/api/v1/health');

        $response
            ->assertStatus(503)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE')
            ->assertJsonPath('error.details.checks.database', 'ok')
            ->assertJsonPath('error.details.checks.cache', 'degraded');
    }

    public function test_request_id_propagates_valid_header_and_sanitizes_invalid_header(): void
    {
        $validResponse = $this->withHeaders([
            'X-Request-ID' => 'flutter-req-12345678',
        ])->get('/api/v1/meta');

        $validResponse
            ->assertOk()
            ->assertHeader('X-Request-ID', 'flutter-req-12345678')
            ->assertJsonPath('meta.request_id', 'flutter-req-12345678');

        $invalidHeader = '<script>alert(1)</script>';
        $sanitizedResponse = $this->withHeaders([
            'X-Request-ID' => $invalidHeader,
        ])->get('/api/v1/meta');

        $sanitizedResponse->assertOk();
        $generatedId = $sanitizedResponse->headers->get('X-Request-ID');
        $this->assertNotSame($invalidHeader, $generatedId);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', (string) $generatedId);
    }

    public function test_meta_endpoint_exposes_flutter_compatible_platform_contract(): void
    {
        $response = $this->get('/api/v1/meta');

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.api_version', 'v1')
            ->assertJsonPath('data.auth_scheme', 'bearer')
            ->assertJsonPath('data.supported_locales', ['en', 'ta'])
            ->assertJsonPath('data.default_currency', 'INR')
            ->assertJsonPath('data.default_timezone', 'Asia/Kolkata')
            ->assertJsonPath('data.flutter_compatible', true);
    }

    public function test_unknown_api_route_returns_standard_json_404(): void
    {
        $response = $this->get('/api/v1/non-existent-endpoint');

        $response
            ->assertNotFound()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('error.code', 'NOT_FOUND')
            ->assertJsonStructure([
                'status',
                'error' => ['code', 'message'],
                'meta' => ['request_id', 'api_version', 'timestamp'],
            ]);
    }

    public function test_disallowed_method_returns_standard_json_405(): void
    {
        $response = $this->post('/api/v1/health');

        $response
            ->assertStatus(405)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('error.code', 'METHOD_NOT_ALLOWED');
    }

    public function test_api_exception_renderer_formats_401_403_and_422_envelopes(): void
    {
        Route::middleware('api')->group(function (): void {
            Route::get('/api/v1/_test/unauth', static function (): never {
                throw new AuthenticationException;
            });
            Route::get('/api/v1/_test/forbid', static function (): never {
                throw new AuthorizationException('Policy denied');
            });
            Route::post('/api/v1/_test/validate', static function (Request $request): void {
                $request->validate([
                    'phone_e164' => ['required', 'string'],
                    'consent_given' => ['accepted'],
                ]);
            });
        });

        $this->get('/api/v1/_test/unauth')
            ->assertUnauthorized()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $this->get('/api/v1/_test/forbid')
            ->assertForbidden()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->post('/api/v1/_test/validate', [])
            ->assertUnprocessable()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure([
                'error' => ['details' => ['phone_e164', 'consent_given']],
            ]);
    }

    public function test_custom_blade_admin_foundation_renders_without_filament(): void
    {
        $response = $this->get('/admin/health');

        $response
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertSee('Custom Blade Admin Foundation')
            ->assertSee('noindex, nofollow');
    }

    public function test_privacy_retention_config_and_legal_exceptions_are_defined(): void
    {
        $this->assertSame(24, config('privacy.retention.otp_hours'));
        $this->assertSame(90, config('privacy.retention.enquiry_pii_days'));
        $this->assertSame(8, config('privacy.retention.tax_record_years'));
        $this->assertTrue(config('privacy.legal_exceptions.gst_tax_invoices.exempt_from_user_erasure'));
        $this->assertTrue(config('privacy.legal_exceptions.active_legal_or_fraud_hold.exempt_from_automated_purge'));
    }

    public function test_composer_contains_no_filament_dependencies(): void
    {
        $composer = json_decode(
            (string) file_get_contents(base_path('composer.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $packages = array_merge(
            array_keys($composer['require'] ?? []),
            array_keys($composer['require-dev'] ?? [])
        );

        foreach ($packages as $package) {
            $this->assertStringNotContainsString(
                'filament',
                strtolower($package),
                'Filament packages are explicitly prohibited by the approved architecture.'
            );
        }
    }

    public function test_production_cache_store_must_be_redis(): void
    {
        // 1. In production, health endpoint marks cache degraded if cache driver is not redis
        $this->app->detectEnvironment(static fn () => 'production');
        config(['cache.default' => 'database']);

        $res = $this->getJson('/api/v1/health');
        $res->assertStatus(503)
            ->assertJsonPath('error.details.checks.cache', 'degraded');

        // 2. AppServiceProvider boot enforces redis cache store in production
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Production configuration violation: CACHE_STORE must be set to 'redis'");

        (new AppServiceProvider($this->app))->boot();
    }

    public function test_production_queue_connection_must_be_redis(): void
    {
        $this->app->detectEnvironment(static fn () => 'production');
        config([
            'cache.default' => 'redis',
            'queue.default' => 'sync',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Production configuration violation: QUEUE_CONNECTION must be set to 'redis'");

        (new AppServiceProvider($this->app))->boot();
    }
}
