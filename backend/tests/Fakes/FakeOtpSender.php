<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domains\Identity\Contracts\OtpSender;
use App\Domains\Identity\Enums\OtpPurpose;

final class FakeOtpSender implements OtpSender
{
    /**
     * @var list<array{phone_e164: string, otp: string, purpose: string, ttl_seconds: int}>
     */
    public array $sent = [];

    public function send(string $phoneE164, string $otpCode, OtpPurpose $purpose, int $ttlSeconds): void
    {
        $this->sent[] = [
            'phone_e164' => $phoneE164,
            'otp' => $otpCode,
            'purpose' => $purpose->value,
            'ttl_seconds' => $ttlSeconds,
        ];
    }

    public function latestOtpFor(string $phoneE164): ?string
    {
        for ($i = count($this->sent) - 1; $i >= 0; $i--) {
            if ($this->sent[$i]['phone_e164'] === $phoneE164) {
                return $this->sent[$i]['otp'];
            }
        }

        return null;
    }
}
