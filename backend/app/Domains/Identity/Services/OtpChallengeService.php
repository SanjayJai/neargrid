<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

use App\Domains\Identity\Contracts\OtpSender;
use App\Domains\Identity\Enums\OtpPurpose;
use App\Domains\Identity\Exceptions\IdentityException;
use App\Domains\Identity\Jobs\SendOtpNotificationJob;
use App\Domains\Identity\Models\OtpChallenge;
use App\Domains\Identity\Support\PhoneNormalizer;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

final class OtpChallengeService
{
    public function __construct(
        private readonly OtpSender $otpSender,
    ) {}

    /**
     * @return array{challenge_id: string, expires_in_seconds: int, resend_after_seconds: int}
     */
    public function requestChallenge(string $rawPhone, OtpPurpose $purpose, string $ipAddress): array
    {
        $phoneE164 = PhoneNormalizer::normalizeAndValidate($rawPhone);

        $this->enforceRequestRateLimits($phoneE164, $ipAddress);
        $this->enforceResendCooldown($phoneE164, $purpose);

        $ttlSeconds = (int) config('directory.otp.ttl_seconds', 300);
        $cooldownSeconds = (int) config('directory.otp.resend_cooldown_seconds', 45);
        $maxAttempts = (int) config('directory.otp.max_attempts', 5);

        $otpCode = (string) random_int(100000, 999999);
        $codeHash = $this->hashOtp($phoneE164, $purpose, $otpCode);
        $ipHash = $this->hashIp($ipAddress);

        $challenge = DB::transaction(function () use ($phoneE164, $purpose, $codeHash, $maxAttempts, $ipHash, $ttlSeconds): OtpChallenge {
            // Expire any older unverified challenges for the same (phone, purpose) to prevent stale replay.
            OtpChallenge::query()
                ->where('phone_e164', $phoneE164)
                ->where('purpose', $purpose->value)
                ->whereNull('verified_at')
                ->where('expires_at', '>', now())
                ->update(['expires_at' => now()->subSecond()]);

            return OtpChallenge::query()->create([
                'phone_e164' => $phoneE164,
                'purpose' => $purpose->value,
                'code_hash' => $codeHash,
                'attempts' => 0,
                'max_attempts' => $maxAttempts,
                'request_ip_hash' => $ipHash,
                'expires_at' => now()->addSeconds($ttlSeconds),
            ]);
        });

        $this->hitRequestRateLimits($phoneE164, $ipAddress);

        // Dispatch OTP SMS asynchronously through Laravel queue with afterCommit to prevent loss or pre-commit firing.
        SendOtpNotificationJob::dispatch(
            phoneE164: $phoneE164,
            otpCode: $otpCode,
            purpose: $purpose,
            ttlSeconds: $ttlSeconds,
            challengeId: $challenge->challenge_id,
        )->afterCommit();

        return [
            'challenge_id' => $challenge->challenge_id,
            'expires_in_seconds' => $ttlSeconds,
            'resend_after_seconds' => $cooldownSeconds,
        ];
    }

    /**
     * @return array{challenge_id: string, expires_in_seconds: int, resend_after_seconds: int}
     */
    public function resendChallenge(string $challengeId, string $rawPhone, OtpPurpose $purpose, string $ipAddress): array
    {
        $phoneE164 = PhoneNormalizer::normalizeAndValidate($rawPhone);

        $existing = OtpChallenge::query()
            ->where('challenge_id', $challengeId)
            ->where('phone_e164', $phoneE164)
            ->where('purpose', $purpose->value)
            ->whereNull('verified_at')
            ->first();

        if ($existing === null) {
            throw new IdentityException(
                errorCode: 'OTP_CHALLENGE_INVALID',
                message: 'The OTP challenge is invalid or can no longer be resent.',
                httpStatus: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $this->requestChallenge($phoneE164, $purpose, $ipAddress);
    }

    public function verifyAndConsume(
        string $challengeId,
        string $rawPhone,
        OtpPurpose $purpose,
        string $otpCode,
        string $ipAddress,
    ): OtpChallenge {
        $phoneE164 = PhoneNormalizer::normalizeAndValidate($rawPhone);
        $this->enforceVerifyIpRateLimit($ipAddress);

        $expectedHash = $this->hashOtp($phoneE164, $purpose, trim($otpCode));

        /** @var array{status: string, challenge: ?OtpChallenge} $result */
        $result = DB::transaction(function () use ($challengeId, $phoneE164, $purpose, $expectedHash): array {
            $challenge = OtpChallenge::query()
                ->where('challenge_id', $challengeId)
                ->where('phone_e164', $phoneE164)
                ->where('purpose', $purpose->value)
                ->lockForUpdate()
                ->first();

            if ($challenge === null || $challenge->isVerified() || $challenge->isExpired()) {
                return ['status' => 'invalid_or_expired', 'challenge' => null];
            }

            if ($challenge->hasExceededAttempts()) {
                return ['status' => 'max_attempts', 'challenge' => null];
            }

            if (! hash_equals($challenge->code_hash, $expectedHash)) {
                // Persist failed attempt inside committed transaction so brute-force tries are never rolled back.
                $newAttempts = $challenge->attempts + 1;
                OtpChallenge::query()
                    ->whereKey($challenge->id)
                    ->update(['attempts' => $newAttempts]);

                return [
                    'status' => $newAttempts >= $challenge->max_attempts ? 'max_attempts' : 'mismatch',
                    'challenge' => null,
                ];
            }

            // Atomic single-use consumption guard (prevents concurrent replay even on drivers without row locks).
            $consumed = OtpChallenge::query()
                ->whereKey($challenge->id)
                ->whereNull('verified_at')
                ->where('expires_at', '>', now())
                ->whereColumn('attempts', '<', 'max_attempts')
                ->update(['verified_at' => now()]);

            if ($consumed !== 1) {
                return ['status' => 'invalid_or_expired', 'challenge' => null];
            }

            $challenge->refresh();

            return ['status' => 'verified', 'challenge' => $challenge];
        });

        if ($result['status'] === 'max_attempts') {
            throw new IdentityException(
                errorCode: 'OTP_MAX_ATTEMPTS',
                message: 'Maximum OTP verification attempts exceeded. Please request a new code.',
                httpStatus: Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        if ($result['status'] !== 'verified' || $result['challenge'] === null) {
            throw new IdentityException(
                errorCode: 'OTP_INVALID_OR_EXPIRED',
                message: 'The verification code is invalid or has expired.',
                httpStatus: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $result['challenge'];
    }

    public function hashOtp(string $phoneE164, OtpPurpose $purpose, string $otpCode): string
    {
        $pepper = (string) config('directory.otp.pepper');
        if ($pepper === '') {
            throw new RuntimeException('OTP pepper (APP_OTP_PEPPER or APP_KEY) must be configured.');
        }

        return hash_hmac('sha256', "{$phoneE164}|{$purpose->value}|{$otpCode}", $pepper);
    }

    private function hashIp(string $ipAddress): string
    {
        $pepper = (string) config('directory.otp.pepper', 'fallback');

        return hash_hmac('sha256', $ipAddress, $pepper);
    }

    private function enforceResendCooldown(string $phoneE164, OtpPurpose $purpose): void
    {
        $cooldownSeconds = (int) config('directory.otp.resend_cooldown_seconds', 45);

        $latest = OtpChallenge::query()
            ->where('phone_e164', $phoneE164)
            ->where('purpose', $purpose->value)
            ->latest('created_at')
            ->first();

        if ($latest === null || $latest->isVerified()) {
            return;
        }

        $elapsed = (int) $latest->created_at->diffInSeconds(now());
        if ($elapsed < $cooldownSeconds) {
            $retryAfter = max(1, $cooldownSeconds - $elapsed);

            throw new IdentityException(
                errorCode: 'OTP_RESEND_COOLDOWN',
                message: 'Please wait before requesting another verification code.',
                httpStatus: Response::HTTP_TOO_MANY_REQUESTS,
                details: ['retry_after_seconds' => $retryAfter],
                headers: ['Retry-After' => (string) $retryAfter],
            );
        }
    }

    private function enforceRequestRateLimits(string $phoneE164, string $ipAddress): void
    {
        $phoneHash = hash('sha256', $phoneE164);
        $ipHash = hash('sha256', $ipAddress);

        $limits = [
            ["otp:req:phone:10m:{$phoneHash}", (int) config('directory.otp.rate_limits.phone_10m', 3)],
            ["otp:req:phone:24h:{$phoneHash}", (int) config('directory.otp.rate_limits.phone_24h', 10)],
            ["otp:req:ip:1h:{$ipHash}", (int) config('directory.otp.rate_limits.ip_1h', 15)],
        ];

        foreach ($limits as [$key, $maxAttempts]) {
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                $retryAfter = max(1, RateLimiter::availableIn($key));

                throw new IdentityException(
                    errorCode: 'RATE_LIMITED',
                    message: 'Too many OTP requests. Please try again later.',
                    httpStatus: Response::HTTP_TOO_MANY_REQUESTS,
                    details: ['retry_after_seconds' => $retryAfter],
                    headers: ['Retry-After' => (string) $retryAfter],
                );
            }
        }
    }

    private function hitRequestRateLimits(string $phoneE164, string $ipAddress): void
    {
        $phoneHash = hash('sha256', $phoneE164);
        $ipHash = hash('sha256', $ipAddress);

        RateLimiter::hit("otp:req:phone:10m:{$phoneHash}", 600);
        RateLimiter::hit("otp:req:phone:24h:{$phoneHash}", 86400);
        RateLimiter::hit("otp:req:ip:1h:{$ipHash}", 3600);
    }

    private function enforceVerifyIpRateLimit(string $ipAddress): void
    {
        $key = 'otp:verify:ip:10m:'.hash('sha256', $ipAddress);
        $max = (int) config('directory.otp.rate_limits.verify_ip_10m', 30);

        if (RateLimiter::tooManyAttempts($key, $max)) {
            $retryAfter = max(1, RateLimiter::availableIn($key));

            throw new IdentityException(
                errorCode: 'RATE_LIMITED',
                message: 'Too many verification attempts. Please try again later.',
                httpStatus: Response::HTTP_TOO_MANY_REQUESTS,
                details: ['retry_after_seconds' => $retryAfter],
                headers: ['Retry-After' => (string) $retryAfter],
            );
        }

        RateLimiter::hit($key, 600);
    }
}
