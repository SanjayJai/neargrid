<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

use App\Domains\Identity\Contracts\OtpSender;
use App\Domains\Identity\Enums\OtpPurpose;
use App\Domains\Identity\Support\PhoneNormalizer;
use Illuminate\Support\Facades\Log;

/**
 * Safe non-sending default driver for local/testing environments.
 * Never logs plaintext OTP codes; logs only masked recipient metadata and trace challengeId.
 */
final class NullOtpSender implements OtpSender
{
    public function send(
        string $phoneE164,
        string $otpCode,
        OtpPurpose $purpose,
        int $ttlSeconds,
        ?string $challengeId = null,
    ): void {
        if (app()->isProduction()) {
            throw new \RuntimeException('NullOtpSender cannot be used to deliver OTPs in production.');
        }

        Log::info('OTP challenge dispatched via NullOtpSender', [
            'recipient_masked' => PhoneNormalizer::mask($phoneE164),
            'purpose' => $purpose->value,
            'ttl_seconds' => $ttlSeconds,
            'challenge_id' => $challengeId,
        ]);
    }
}
