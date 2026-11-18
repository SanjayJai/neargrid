<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

use App\Domains\Identity\Contracts\OtpSender;
use App\Domains\Identity\Enums\OtpPurpose;
use Illuminate\Support\Facades\Log;

/**
 * Safe non-sending default driver for local/testing environments.
 * Never logs plaintext OTP codes; logs only masked recipient metadata.
 */
final class NullOtpSender implements OtpSender
{
    public function send(string $phoneE164, string $otpCode, OtpPurpose $purpose, int $ttlSeconds): void
    {
        $masked = substr($phoneE164, 0, 3).'******'.substr($phoneE164, -4);

        Log::info('OTP challenge dispatched via NullOtpSender', [
            'recipient_masked' => $masked,
            'purpose' => $purpose->value,
            'ttl_seconds' => $ttlSeconds,
        ]);
    }
}
