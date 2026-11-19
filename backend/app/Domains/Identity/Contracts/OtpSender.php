<?php

declare(strict_types=1);

namespace App\Domains\Identity\Contracts;

use App\Domains\Identity\Enums\OtpPurpose;

interface OtpSender
{
    /**
     * Deliver a one-time passcode to the given E.164 phone number.
     * Implementations must NEVER log plaintext OTP codes.
     *
     * @param  string|null  $challengeId  Stable challenge identifier used as an idempotency key where supported by provider.
     */
    public function send(
        string $phoneE164,
        string $otpCode,
        OtpPurpose $purpose,
        int $ttlSeconds,
        ?string $challengeId = null,
    ): void;
}
