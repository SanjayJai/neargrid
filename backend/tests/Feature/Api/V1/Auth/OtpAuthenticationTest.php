<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Auth;

use App\Domains\Identity\Contracts\OtpSender;
use App\Domains\Identity\Enums\OtpPurpose;
use App\Domains\Identity\Models\OtpChallenge;
use App\Domains\Identity\Models\Role;
use App\Domains\Identity\Services\NullOtpSender;
use App\Domains\Identity\Services\OtpChallengeService;
use App\Models\User;
use Database\Seeders\Reference\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Fakes\FakeOtpSender;
use Tests\TestCase;

final class OtpAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private FakeOtpSender $fakeOtpSender;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->fakeOtpSender = new FakeOtpSender;
        $this->app->instance(OtpSender::class, $this->fakeOtpSender);
        RateLimiter::clear('otp:req:ip:1h:'.hash('sha256', '127.0.0.1'));
    }

    public function test_successful_otp_registration_and_subsequent_login(): void
    {
        $phone = '+919840012345';

        // 1. Request OTP for brand-new user
        $reqResponse = $this->postJson('/api/v1/auth/otp/request', [
            'phone_e164' => $phone,
            'purpose' => 'login',
        ]);

        $reqResponse
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'status',
                'data' => ['challenge_id', 'expires_in_seconds', 'resend_after_seconds'],
                'meta' => ['request_id', 'api_version', 'timestamp'],
            ]);

        $challengeId = (string) $reqResponse->json('data.challenge_id');
        $sentOtp = $this->fakeOtpSender->latestOtpFor($phone);

        $this->assertNotNull($sentOtp);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $sentOtp);
        $this->assertStringNotContainsString($sentOtp, $reqResponse->getContent() ?: '');

        // Ensure DB stores HMAC hash, never plaintext OTP
        $challenge = OtpChallenge::query()->where('challenge_id', $challengeId)->firstOrFail();
        $this->assertNotSame($sentOtp, $challenge->code_hash);
        $this->assertSame(64, strlen($challenge->code_hash));

        // 2. Verify OTP -> creates user, assigns customer role, issues Sanctum Bearer token
        $verifyResponse = $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $challengeId,
            'phone_e164' => $phone,
            'purpose' => 'login',
            'otp' => $sentOtp,
            'name' => 'Karthik Sundaram',
            'device_name' => 'Pixel 8 Pro',
            'platform' => 'android',
            'device_identifier' => 'android-pixel-8-001',
            'fcm_token' => 'secret_fcm_token_value',
            'app_version' => '1.0.0',
        ]);

        $verifyResponse
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.name', 'Karthik Sundaram')
            ->assertJsonPath('data.user.phone_e164', $phone)
            ->assertJsonPath('data.user.roles', ['customer'])
            ->assertJsonPath('data.device.platform', 'android')
            ->assertJsonPath('data.device.has_push_token', true);

        $firstPublicId = (string) $verifyResponse->json('data.user.public_id');
        $this->assertTrue(Str::isUlid($firstPublicId));

        // Confirm no sensitive auth data or internal database IDs leaked in response
        $rawBody = (string) $verifyResponse->getContent();
        $this->assertStringNotContainsString('secret_fcm_token_value', $rawBody);
        $this->assertStringNotContainsString($challenge->code_hash, $rawBody);
        $this->assertArrayNotHasKey('id', $verifyResponse->json('data.user'));
        $this->assertArrayNotHasKey('password', $verifyResponse->json('data.user'));
        $this->assertArrayNotHasKey('totp_secret_encrypted', $verifyResponse->json('data.user'));
        $this->assertArrayNotHasKey('id', $verifyResponse->json('data.device'));

        // 3. Subsequent login for existing user after cooldown reuses same user account
        $this->travel(60)->seconds();

        $loginReq = $this->postJson('/api/v1/auth/otp/request', [
            'phone_e164' => $phone,
            'purpose' => 'login',
        ])->assertOk();

        $loginOtp = $this->fakeOtpSender->latestOtpFor($phone);
        $loginVerify = $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $loginReq->json('data.challenge_id'),
            'phone_e164' => $phone,
            'purpose' => 'login',
            'otp' => $loginOtp,
        ])->assertOk();

        $this->assertSame($firstPublicId, $loginVerify->json('data.user.public_id'));
        $this->assertSame(1, User::query()->where('phone_e164', $phone)->count());
    }

    public function test_anti_user_enumeration_and_privilege_escalation_prevention(): void
    {
        $existingUser = User::factory()->create(['phone_e164' => '+919840099999']);
        $superAdminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $existingUser->roles()->attach($superAdminRole->id);

        $resExisting = $this->postJson('/api/v1/auth/otp/request', [
            'phone_e164' => '+919840099999',
            'purpose' => 'login',
        ])->assertOk();

        $resNew = $this->postJson('/api/v1/auth/otp/request', [
            'phone_e164' => '+919840088888',
            'purpose' => 'login',
        ])->assertOk();

        // Identical top-level keys prevent phone enumeration
        $this->assertSame(
            array_keys($resExisting->json('data')),
            array_keys($resNew->json('data'))
        );

        // Attempt to self-grant super_admin during registration payload -> strictly ignored
        $newOtp = $this->fakeOtpSender->latestOtpFor('+919840088888');
        $regVerify = $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $resNew->json('data.challenge_id'),
            'phone_e164' => '+919840088888',
            'purpose' => 'login',
            'otp' => $newOtp,
            'roles' => ['super_admin', 'kyc_verifier'],
            'role' => 'super_admin',
            'is_admin' => true,
        ])->assertOk();

        $this->assertSame(['customer'], $regVerify->json('data.user.roles'));

        // Even when a super_admin authenticates via OTP API, admin abilities are NEVER issued to Bearer tokens
        $adminOtp = $this->fakeOtpSender->latestOtpFor('+919840099999');
        $adminVerify = $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $resExisting->json('data.challenge_id'),
            'phone_e164' => '+919840099999',
            'purpose' => 'login',
            'otp' => $adminOtp,
        ])->assertOk();

        $adminToken = $existingUser->tokens()->latest()->firstOrFail();
        $this->assertNotContains('*', $adminToken->abilities);
        $this->assertNotContains('admin:*', $adminToken->abilities);
        $this->assertNotContains('kyc.review', $adminToken->abilities);

        // Disallowed purpose (e.g., admin_login or enquiry_verify) rejected on auth endpoint
        $this->postJson('/api/v1/auth/otp/request', [
            'phone_e164' => '+919840077777',
            'purpose' => 'enquiry_verify',
        ])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_invalid_wrong_binding_and_expired_otp_are_rejected(): void
    {
        $phone = '+919840011111';
        $req = $this->postJson('/api/v1/auth/otp/request', [
            'phone_e164' => $phone,
            'purpose' => 'login',
        ])->assertOk();

        $challengeId = (string) $req->json('data.challenge_id');
        $validOtp = (string) $this->fakeOtpSender->latestOtpFor($phone);
        $wrongOtp = $validOtp === '000000' ? '999999' : '000000';

        // Wrong OTP
        $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $challengeId,
            'phone_e164' => $phone,
            'purpose' => 'login',
            'otp' => $wrongOtp,
        ])->assertUnprocessable()->assertJsonPath('error.code', 'OTP_INVALID_OR_EXPIRED');

        // Wrong phone binding
        $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $challengeId,
            'phone_e164' => '+919840022222',
            'purpose' => 'login',
            'otp' => $validOtp,
        ])->assertUnprocessable()->assertJsonPath('error.code', 'OTP_INVALID_OR_EXPIRED');

        // Wrong purpose binding
        $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $challengeId,
            'phone_e164' => $phone,
            'purpose' => 'business_register',
            'otp' => $validOtp,
        ])->assertUnprocessable()->assertJsonPath('error.code', 'OTP_INVALID_OR_EXPIRED');

        // Expired OTP (past 5-minute TTL)
        $this->travel(6)->minutes();

        $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $challengeId,
            'phone_e164' => $phone,
            'purpose' => 'login',
            'otp' => $validOtp,
        ])->assertUnprocessable()->assertJsonPath('error.code', 'OTP_INVALID_OR_EXPIRED');
    }

    public function test_otp_atomic_single_use_prevents_replay_and_concurrent_reuse(): void
    {
        $phone = '+919840033333';
        $req = $this->postJson('/api/v1/auth/otp/request', [
            'phone_e164' => $phone,
            'purpose' => 'login',
        ])->assertOk();

        $challengeId = (string) $req->json('data.challenge_id');
        $validOtp = (string) $this->fakeOtpSender->latestOtpFor($phone);

        // First verification succeeds and atomically consumes the challenge
        $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $challengeId,
            'phone_e164' => $phone,
            'purpose' => 'login',
            'otp' => $validOtp,
        ])->assertOk();

        // Immediate second verification (replay / concurrent reuse) must fail
        $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $challengeId,
            'phone_e164' => $phone,
            'purpose' => 'login',
            'otp' => $validOtp,
        ])->assertUnprocessable()->assertJsonPath('error.code', 'OTP_INVALID_OR_EXPIRED');

        // Direct service concurrency check: only 1 of 2 back-to-back calls can consume a challenge
        $this->travel(60)->seconds();
        $service = app(OtpChallengeService::class);
        $second = $service->requestChallenge($phone, OtpPurpose::Login, '127.0.0.2');
        $secondOtp = (string) $this->fakeOtpSender->latestOtpFor($phone);

        $consumedCount = 0;
        for ($i = 0; $i < 2; $i++) {
            try {
                $service->verifyAndConsume($second['challenge_id'], $phone, OtpPurpose::Login, $secondOtp, '127.0.0.2');
                $consumedCount++;
            } catch (\Throwable) {
                // Expected on second attempt
            }
        }

        $this->assertSame(1, $consumedCount);
    }

    public function test_otp_resend_cooldown_and_max_attempt_lockout(): void
    {
        $phone = '+919840044444';
        $first = $this->postJson('/api/v1/auth/otp/request', [
            'phone_e164' => $phone,
            'purpose' => 'login',
        ])->assertOk();

        $firstChallengeId = (string) $first->json('data.challenge_id');
        $firstOtp = (string) $this->fakeOtpSender->latestOtpFor($phone);

        // Immediate resend blocked by 45s cooldown
        $cooldownRes = $this->postJson('/api/v1/auth/otp/resend', [
            'challenge_id' => $firstChallengeId,
            'phone_e164' => $phone,
            'purpose' => 'login',
        ]);

        $cooldownRes
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'OTP_RESEND_COOLDOWN');
        $this->assertNotNull($cooldownRes->headers->get('Retry-After'));

        // After 46 seconds, resend succeeds and expires old challenge
        $this->travel(46)->seconds();
        $resent = $this->postJson('/api/v1/auth/otp/resend', [
            'challenge_id' => $firstChallengeId,
            'phone_e164' => $phone,
            'purpose' => 'login',
        ])->assertOk();

        $newChallengeId = (string) $resent->json('data.challenge_id');
        $newOtp = (string) $this->fakeOtpSender->latestOtpFor($phone);
        $this->assertNotSame($firstChallengeId, $newChallengeId);

        // Old OTP is invalidated
        $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $firstChallengeId,
            'phone_e164' => $phone,
            'purpose' => 'login',
            'otp' => $firstOtp,
        ])->assertUnprocessable();

        // Exhaust 5 failed attempts on new challenge
        $wrongOtp = $newOtp === '111111' ? '222222' : '111111';
        for ($i = 1; $i <= 4; $i++) {
            $this->postJson('/api/v1/auth/otp/verify', [
                'challenge_id' => $newChallengeId,
                'phone_e164' => $phone,
                'purpose' => 'login',
                'otp' => $wrongOtp,
            ])->assertUnprocessable()->assertJsonPath('error.code', 'OTP_INVALID_OR_EXPIRED');
        }

        // 5th wrong attempt triggers OTP_MAX_ATTEMPTS lockout
        $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $newChallengeId,
            'phone_e164' => $phone,
            'purpose' => 'login',
            'otp' => $wrongOtp,
        ])->assertStatus(429)->assertJsonPath('error.code', 'OTP_MAX_ATTEMPTS');

        // Even the real OTP is now rejected because attempts are persisted and locked
        $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $newChallengeId,
            'phone_e164' => $phone,
            'purpose' => 'login',
            'otp' => $newOtp,
        ])->assertStatus(429)->assertJsonPath('error.code', 'OTP_MAX_ATTEMPTS');
    }

    public function test_rate_limiting_across_phone_and_ip_and_country_code_allowlist(): void
    {
        // Unsupported country code blocked (anti SMS pumping)
        $this->postJson('/api/v1/auth/otp/request', [
            'phone_e164' => '+14155552671',
            'purpose' => 'login',
        ])->assertUnprocessable()->assertJsonPath('error.code', 'UNSUPPORTED_COUNTRY_CODE');

        // Per-phone 10-minute rate limit (max 3)
        $phone = '+919840055555';
        for ($i = 1; $i <= 3; $i++) {
            $this->postJson('/api/v1/auth/otp/request', [
                'phone_e164' => $phone,
                'purpose' => 'login',
            ])->assertOk();
            $this->travel(50)->seconds();
        }

        $this->postJson('/api/v1/auth/otp/request', [
            'phone_e164' => $phone,
            'purpose' => 'login',
        ])->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');

        // Per-IP 1-hour rate limit across distinct phones
        config()->set('directory.otp.rate_limits.ip_1h', 2);
        $ip = '10.20.30.40';

        $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v1/auth/otp/request', [
            'phone_e164' => '+919840061111',
            'purpose' => 'login',
        ])->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v1/auth/otp/request', [
            'phone_e164' => '+919840062222',
            'purpose' => 'login',
        ])->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v1/auth/otp/request', [
            'phone_e164' => '+919840063333',
            'purpose' => 'login',
        ])->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    public function test_null_otp_sender_never_logs_plaintext_otp_code(): void
    {
        $loggedMessages = [];
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context) use (&$loggedMessages): bool {
                $loggedMessages[] = $message.' '.json_encode($context);

                return true;
            });

        $sender = new NullOtpSender;
        $sender->send('+919840012345', '847291', OtpPurpose::Login, 300);

        $combined = implode(' ', $loggedMessages);
        $this->assertStringNotContainsString('847291', $combined);
        $this->assertStringNotContainsString('+919840012345', $combined);
        $this->assertStringContainsString('+91******2345', $combined);
    }
}
