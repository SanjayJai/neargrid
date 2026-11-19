<?php

declare(strict_types=1);

namespace App\Domains\Identity\Jobs;

use App\Domains\Identity\Contracts\OtpSender;
use App\Domains\Identity\Enums\OtpPurpose;
use App\Domains\Identity\Support\PhoneNormalizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SendOtpNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public readonly string $phoneE164,
        public readonly string $otpCode,
        public readonly OtpPurpose $purpose,
        public readonly int $ttlSeconds,
    ) {
        $this->onQueue((string) config('directory.otp.queue', 'notifications'));
    }

    /**
     * Exponential retry backoff in seconds between delivery attempts.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [2, 10, 30];
    }

    public function handle(OtpSender $otpSender): void
    {
        // Deliver OTP via pluggable provider; never log or return plaintext code.
        $otpSender->send($this->phoneE164, $this->otpCode, $this->purpose, $this->ttlSeconds);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Asynchronous OTP delivery job failed permanently after retries', [
            'phone' => PhoneNormalizer::mask($this->phoneE164),
            'purpose' => $this->purpose->value,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
