<?php

declare(strict_types=1);

namespace App\Domains\Media\Jobs;

use App\Domains\Directory\Services\BusinessCompletenessCalculator;
use App\Domains\Media\Enums\MediaUploadStatus;
use App\Domains\Media\Models\BusinessMedia;
use App\Domains\Media\Services\ImageProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ProcessBusinessMediaJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 120;

    public function __construct(
        public readonly int $businessMediaId,
    ) {
        $this->onQueue((string) config('media.queue', 'media'));
    }

    /**
     * Exponential retry backoff in seconds.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(
        ImageProcessor $processor,
        BusinessCompletenessCalculator $completenessCalculator,
    ): void {
        $media = BusinessMedia::query()->with('business')->find($this->businessMediaId);
        if ($media === null || $media->business === null) {
            return;
        }

        $media->upload_status = MediaUploadStatus::Processing;
        $media->failure_reason = null;
        $media->save();

        $disk = Storage::disk($media->disk);
        $stagingKey = $media->storage_key;

        if (! $disk->exists($stagingKey)) {
            throw new RuntimeException('Staged media source file is missing.');
        }

        $rawBytes = $disk->get($stagingKey);
        if (! is_string($rawBytes) || $rawBytes === '') {
            throw new RuntimeException('Staged media source file could not be read.');
        }

        $processed = $processor->generateWebpVariantsFromBytes($rawBytes, $media->mime_type);
        $variants = $processed['variants'];

        $businessPublicId = $media->business->public_id;
        $basePrefix = "businesses/{$businessPublicId}/{$media->public_id}";
        $thumbKey = "{$basePrefix}/thumb.webp";
        $cardKey = "{$basePrefix}/card.webp";
        $fullKey = "{$basePrefix}/full.webp";

        $writtenKeys = [];

        try {
            $uploads = [
                $thumbKey => $variants['thumb']['bytes'],
                $cardKey => $variants['card']['bytes'],
                $fullKey => $variants['full']['bytes'],
            ];

            foreach ($uploads as $key => $bytes) {
                $stored = $disk->put($key, $bytes, [
                    'visibility' => 'public',
                    'ContentType' => 'image/webp',
                ]);

                if ($stored === false) {
                    throw new RuntimeException("Storage write returned false for key {$key}.");
                }

                $writtenKeys[] = $key;
            }
        } catch (Throwable $exception) {
            if ($writtenKeys !== []) {
                try {
                    $disk->delete($writtenKeys);
                } catch (Throwable) {
                    // Best-effort cleanup before propagating for queue retry.
                }
            }

            throw $exception;
        }

        $preserveOriginals = (bool) config('media.preserve_originals', false);

        if ($preserveOriginals) {
            $media->original_key = $stagingKey;
            $media->storage_key = $fullKey;
        } else {
            if ($stagingKey !== $fullKey) {
                try {
                    $disk->delete($stagingKey);
                } catch (Throwable) {
                    // Non-fatal staging cleanup; variants are already persisted.
                }
            }
            $media->original_key = null;
            $media->storage_key = $fullKey;
        }

        $media->webp_thumb_key = $thumbKey;
        $media->webp_card_key = $cardKey;
        $media->webp_full_key = $fullKey;
        $media->mime_type = 'image/webp';
        $media->file_size_bytes = strlen($variants['full']['bytes']);
        $media->width = $variants['full']['width'];
        $media->height = $variants['full']['height'];
        $media->exif_stripped = true;
        $media->upload_status = MediaUploadStatus::Ready;
        $media->failure_reason = null;
        $media->processed_at = now();
        $media->save();

        $business = $media->business;
        $business->photo_count = $business->media()->publicApproved()->count();
        $business->completeness_score = $completenessCalculator->calculate($business);
        $business->save();
    }

    public function failed(?Throwable $exception): void
    {
        $media = BusinessMedia::query()->find($this->businessMediaId);
        if ($media === null) {
            return;
        }

        $disk = Storage::disk($media->disk);
        $keysToClean = array_values(array_filter([
            $media->webp_thumb_key,
            $media->webp_card_key,
            $media->webp_full_key,
        ]));

        if (! (bool) config('media.preserve_originals', false) && str_contains($media->storage_key, '/staging/')) {
            $keysToClean[] = $media->storage_key;
        }

        if ($keysToClean !== []) {
            try {
                $disk->delete($keysToClean);
            } catch (Throwable) {
                // Best-effort cleanup on permanent job failure.
            }
        }

        $media->upload_status = MediaUploadStatus::Failed;
        $media->webp_thumb_key = null;
        $media->webp_card_key = null;
        $media->webp_full_key = null;
        $media->failure_reason = Str::limit(
            $exception?->getMessage() ?: 'Media processing failed.',
            240,
        );
        $media->save();
    }
}
