<?php

declare(strict_types=1);

namespace App\Domains\Media\Services;

use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Services\BusinessCompletenessCalculator;
use App\Domains\Governance\Services\AuditLogger;
use App\Domains\Media\Enums\BusinessMediaType;
use App\Domains\Media\Enums\MediaUploadStatus;
use App\Domains\Media\Exceptions\MediaException;
use App\Domains\Media\Jobs\ProcessBusinessMediaJob;
use App\Domains\Media\Models\BusinessMedia;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class BusinessMediaService
{
    public function __construct(
        private readonly FileSignatureValidator $signatureValidator,
        private readonly BusinessCompletenessCalculator $completenessCalculator,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function uploadPublicMedia(
        User $actor,
        Business $business,
        UploadedFile $file,
        BusinessMediaType $mediaType,
        ?string $altText = null,
        int $sortOrder = 0,
    ): BusinessMedia {
        $validated = $this->signatureValidator->validatePublicImage($file);

        if (! $mediaType->isSingleton()) {
            $maxGallery = (int) config('media.limits.max_gallery_per_business', 20);
            $currentCount = BusinessMedia::query()
                ->where('business_id', $business->id)
                ->whereNotIn('media_type', [BusinessMediaType::Logo->value, BusinessMediaType::Cover->value])
                ->where('upload_status', '!=', MediaUploadStatus::Failed->value)
                ->count();

            if ($currentCount >= $maxGallery) {
                throw new MediaException(
                    'MEDIA_LIMIT_EXCEEDED',
                    "A business may have at most {$maxGallery} gallery images.",
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                    ['max_gallery_per_business' => $maxGallery],
                );
            }
        }

        $diskName = (string) config('media.public_disk', 'media_public');
        $disk = Storage::disk($diskName);

        $mediaPublicId = (string) Str::ulid();
        $stagingKey = "businesses/{$business->public_id}/staging/{$mediaPublicId}.{$validated->extension}";

        $rawBytes = @file_get_contents($validated->realPath);
        if (! is_string($rawBytes) || $rawBytes === '') {
            throw new MediaException(
                'UPLOAD_READ_FAILED',
                'The uploaded file could not be read.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $stored = $disk->put($stagingKey, $rawBytes, [
                'visibility' => 'private',
                'ContentType' => $validated->mimeType,
            ]);
            if ($stored === false) {
                throw new MediaException(
                    'MEDIA_STORAGE_ERROR',
                    'Failed to write uploaded media to storage.',
                    Response::HTTP_INTERNAL_SERVER_ERROR,
                );
            }
        } catch (MediaException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new MediaException(
                'MEDIA_STORAGE_ERROR',
                'Failed to write uploaded media to storage.',
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        /** @var list<array{disk: string, keys: list<string>}> $replacedObjects */
        $replacedObjects = [];

        try {
            /** @var BusinessMedia $media */
            $media = DB::transaction(function () use (
                $actor,
                $business,
                $mediaType,
                $diskName,
                $stagingKey,
                $mediaPublicId,
                $validated,
                $altText,
                $sortOrder,
                &$replacedObjects,
            ): BusinessMedia {
                if ($mediaType->isSingleton()) {
                    $existingSingletons = BusinessMedia::query()
                        ->where('business_id', $business->id)
                        ->where('media_type', $mediaType->value)
                        ->get();

                    foreach ($existingSingletons as $existing) {
                        $replacedObjects[] = [
                            'disk' => $existing->disk,
                            'keys' => $existing->allStorageKeys(),
                        ];
                        $existing->delete();
                    }
                }

                $record = new BusinessMedia([
                    'business_id' => $business->id,
                    'uploaded_by_user_id' => $actor->id,
                    'media_type' => $mediaType,
                    'upload_status' => MediaUploadStatus::Pending,
                    'moderation_status' => 'approved',
                    'disk' => $diskName,
                    'storage_key' => $stagingKey,
                    'mime_type' => $validated->mimeType,
                    'file_size_bytes' => $validated->sizeBytes,
                    'width' => $validated->width,
                    'height' => $validated->height,
                    'exif_stripped' => false,
                    'alt_text' => $altText !== null && trim($altText) !== '' ? trim($altText) : null,
                    'sort_order' => max(0, $sortOrder),
                ]);
                $record->public_id = $mediaPublicId;
                $record->save();

                $this->auditLogger->record(
                    actor: $actor,
                    action: 'business.media_uploaded',
                    auditable: $business,
                    newValues: [
                        'media_public_id' => $record->public_id,
                        'media_type' => $mediaType->value,
                        'file_size_bytes' => $validated->sizeBytes,
                    ],
                );

                return $record;
            });
        } catch (Throwable $exception) {
            try {
                $disk->delete($stagingKey);
            } catch (Throwable) {
                // Best-effort cleanup of orphaned staged object.
            }

            throw $exception;
        }

        // Remove replaced singleton files only after the new DB record is committed.
        foreach ($replacedObjects as $old) {
            if ($old['keys'] !== []) {
                try {
                    Storage::disk($old['disk'])->delete($old['keys']);
                } catch (Throwable) {
                    // Best-effort cleanup of replaced singleton files.
                }
            }
        }

        $asyncThreshold = (int) config('media.async_threshold_bytes', 524288);
        if ($validated->sizeBytes <= $asyncThreshold) {
            ProcessBusinessMediaJob::dispatchSync($media->id);
            $media->refresh();
        } else {
            ProcessBusinessMediaJob::dispatch($media->id);
        }

        return $media;
    }

    public function deletePublicMedia(User $actor, Business $business, BusinessMedia $media): void
    {
        $diskName = $media->disk;
        $storageKeys = $media->allStorageKeys();
        $mediaPublicId = $media->public_id;
        $mediaType = $media->media_type->value;

        DB::transaction(function () use ($actor, $business, $media, $mediaPublicId, $mediaType): void {
            $media->delete();

            $business->photo_count = $business->media()->publicApproved()->count();
            $business->completeness_score = $this->completenessCalculator->calculate($business);
            $business->save();

            $this->auditLogger->record(
                actor: $actor,
                action: 'business.media_deleted',
                auditable: $business,
                oldValues: [
                    'media_public_id' => $mediaPublicId,
                    'media_type' => $mediaType,
                ],
            );
        });

        if ($storageKeys !== []) {
            try {
                Storage::disk($diskName)->delete($storageKeys);
            } catch (Throwable) {
                // Best-effort object cleanup.
            }
        }
    }
}
