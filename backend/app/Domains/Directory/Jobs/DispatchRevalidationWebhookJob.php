<?php

declare(strict_types=1);

namespace App\Domains\Directory\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class DispatchRevalidationWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 10;

    /**
     * @param  list<string>  $paths
     */
    public function __construct(
        public readonly array $paths,
        public readonly ?string $reason = null,
    ) {
        $this->onQueue((string) config('directory.revalidation.queue', 'notifications'));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [2, 10, 30];
    }

    public function handle(): void
    {
        $uniquePaths = array_values(array_filter(
            array_unique($this->paths),
            static fn (mixed $p): bool => is_string($p) && str_starts_with($p, '/')
        ));

        if ($uniquePaths === []) {
            return;
        }

        // Avoid accidental external HTTP traffic during unit/feature tests unless Http::fake() is configured.
        if (app()->runningUnitTests()) {
            $reflector = new \ReflectionClass(Http::getFacadeRoot());
            $stubCallbacksProp = $reflector->getProperty('stubCallbacks');
            $stubCallbacksProp->setAccessible(true);
            /** @var Collection $stubs */
            $stubs = $stubCallbacksProp->getValue(Http::getFacadeRoot());
            if ($stubs->isEmpty()) {
                return;
            }
        }

        $frontendUrl = rtrim((string) config('directory.revalidation.frontend_url', env('FRONTEND_URL', 'http://localhost:3000')), '/');
        $secret = (string) config('directory.revalidation.secret', env('REVALIDATION_WEBHOOK_SECRET', config('app.key')));

        if ($frontendUrl === '' || $secret === '') {
            Log::warning('ISR revalidation webhook skipped: FRONTEND_URL or REVALIDATION_WEBHOOK_SECRET is not configured.', [
                'paths' => $uniquePaths,
            ]);

            return;
        }

        $timestamp = time();
        $payload = json_encode(['paths' => $uniquePaths], JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        $response = Http::withHeaders([
            'X-Webhook-Signature' => "t={$timestamp},v1={$signature}",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->timeout(5)->post("{$frontendUrl}/api/revalidate", json_decode($payload, true, 512, JSON_THROW_ON_ERROR));

        if ($response->failed()) {
            throw new RuntimeException("Revalidation webhook failed with HTTP status {$response->status()}: {$response->body()}");
        }

        Log::info('Next.js ISR revalidation webhook succeeded', [
            'paths' => $uniquePaths,
            'reason' => $this->reason,
            'status' => $response->status(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Next.js ISR revalidation webhook failed permanently after all retries', [
            'paths' => $this->paths,
            'reason' => $this->reason,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
