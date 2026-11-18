<?php

declare(strict_types=1);

namespace App\Domains\Media\Services;

use App\Domains\Directory\Enums\VerificationDocumentType;
use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Services\BusinessVerificationAndClaimService;
use App\Domains\Governance\Services\AuditLogger;
use App\Domains\Media\Exceptions\MediaException;
use App\Domains\Media\Models\KycDocument;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

final class KycDocumentService
{
    public function __construct(
        private readonly FileSignatureValidator $signatureValidator,
        private readonly ImageProcessor $imageProcessor,
        private readonly BusinessVerificationAndClaimService $verificationService,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function uploadKycDocument(
        User $actor,
        Business $business,
        UploadedFile $file,
        VerificationDocumentType $documentType,
        ?string $documentNumber = null,
    ): KycDocument {
        $maxDocs = (int) config('media.limits.max_kyc_docs_per_business', 10);
        $currentCount = KycDocument::query()
            ->where('business_id', $business->id)
            ->count();

        if ($currentCount >= $maxDocs) {
            throw new MediaException(
                'KYC_DOCUMENT_LIMIT_EXCEEDED',
                "A business may upload at most {$maxDocs} KYC documents.",
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['max_kyc_docs_per_business' => $maxDocs],
            );
        }

        $validated = $this->signatureValidator->validateKycDocument($file);
        $rawBytes = @file_get_contents($validated->realPath);
        if (! is_string($rawBytes) || $rawBytes === '') {
            throw new MediaException(
                'UPLOAD_READ_FAILED',
                'The uploaded KYC document could not be read.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ($validated->isImage) {
            $sanitized = $this->imageProcessor->stripExifToWebpBytes($rawBytes, $validated->mimeType);
            $storedBytes = $sanitized['bytes'];
            $storedMime = $sanitized['mime_type'];
            $storedExtension = 'webp';
            $width = $sanitized['width'];
            $height = $sanitized['height'];
        } else {
            $storedBytes = $rawBytes;
            $storedMime = 'application/pdf';
            $storedExtension = 'pdf';
            $width = null;
            $height = null;
        }

        $diskName = (string) config('media.kyc_disk', 'kyc_private');
        $disk = Storage::disk($diskName);
        $documentPublicId = (string) Str::ulid();
        $storageKey = "kyc/{$business->public_id}/{$documentPublicId}.{$storedExtension}";
        $checksum = hash('sha256', $storedBytes);
        $sizeBytes = strlen($storedBytes);

        try {
            $stored = $disk->put($storageKey, $storedBytes, [
                'visibility' => 'private',
                'ContentType' => $storedMime,
            ]);
            if ($stored === false) {
                throw new MediaException(
                    'KYC_STORAGE_ERROR',
                    'Failed to persist KYC document to private storage.',
                    Response::HTTP_INTERNAL_SERVER_ERROR,
                );
            }
        } catch (MediaException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new MediaException(
                'KYC_STORAGE_ERROR',
                'Failed to persist KYC document to private storage.',
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        try {
            /** @var KycDocument $document */
            $document = DB::transaction(function () use (
                $actor,
                $business,
                $documentType,
                $documentNumber,
                $diskName,
                $storageKey,
                $documentPublicId,
                $storedMime,
                $sizeBytes,
                $width,
                $height,
                $checksum,
            ): KycDocument {
                $verificationId = null;
                if ($documentNumber !== null && trim($documentNumber) !== '') {
                    $verification = $this->verificationService->submitVerification(
                        actor: $actor,
                        business: $business,
                        documentType: $documentType,
                        documentNumber: $documentNumber,
                        privateFilePath: $storageKey,
                    );
                    $verificationId = $verification->id;
                }

                $record = new KycDocument([
                    'business_id' => $business->id,
                    'business_verification_id' => $verificationId,
                    'uploaded_by_user_id' => $actor->id,
                    'document_type' => $documentType,
                    'upload_status' => 'ready',
                    'disk' => $diskName,
                    'storage_key' => $storageKey,
                    'mime_type' => $storedMime,
                    'file_size_bytes' => $sizeBytes,
                    'width' => $width,
                    'height' => $height,
                    'sha256_checksum' => $checksum,
                ]);
                $record->public_id = $documentPublicId;
                $record->save();

                $this->auditLogger->record(
                    actor: $actor,
                    action: 'kyc.document_uploaded',
                    auditable: $business,
                    newValues: [
                        'kyc_document_public_id' => $record->public_id,
                        'document_type' => $documentType->value,
                        'mime_type' => $storedMime,
                        'file_size_bytes' => $sizeBytes,
                    ],
                );

                return $record;
            });

            return $document;
        } catch (Throwable $exception) {
            try {
                $disk->delete($storageKey);
            } catch (Throwable) {
                // Best-effort cleanup of orphaned private file.
            }

            throw $exception;
        }
    }

    /**
     * Issue a short-lived, actor-bound signed download URL for private KYC access.
     *
     * @return array{download_url: string, expires_at: string, ttl_seconds: int}
     */
    public function issueSignedDownloadUrl(User $actor, KycDocument $document): array
    {
        $ttl = max(10, min(300, (int) config('media.kyc_signed_url_ttl_seconds', 60)));
        $expiresAt = now()->addSeconds($ttl);

        $url = URL::temporarySignedRoute(
            'api.v1.kyc.download',
            $expiresAt,
            [
                'kycDocument' => $document->public_id,
                'actor' => $actor->public_id,
            ],
        );

        $this->auditLogger->record(
            actor: $actor,
            action: 'kyc.signed_url_issued',
            auditable: $document->business,
            newValues: [
                'kyc_document_public_id' => $document->public_id,
                'ttl_seconds' => $ttl,
                'expires_at' => $expiresAt->toIso8601String(),
            ],
        );

        return [
            'download_url' => $url,
            'expires_at' => $expiresAt->toIso8601String(),
            'ttl_seconds' => $ttl,
        ];
    }

    /**
     * Read private KYC bytes for an authorized download request and write audit trail.
     *
     * @return array{bytes: string, mime_type: string, filename: string}
     */
    public function readDocumentForDownload(User $actor, KycDocument $document): array
    {
        $disk = Storage::disk($document->disk);

        if (! $disk->exists($document->storage_key)) {
            throw new MediaException(
                'KYC_FILE_NOT_FOUND',
                'The requested KYC document file is no longer available.',
                Response::HTTP_NOT_FOUND,
            );
        }

        $bytes = $disk->get($document->storage_key);
        if (! is_string($bytes)) {
            throw new MediaException(
                'KYC_FILE_UNREADABLE',
                'Unable to read the requested KYC document.',
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        $this->auditLogger->record(
            actor: $actor,
            action: 'kyc.document_downloaded',
            auditable: $document->business,
            newValues: [
                'kyc_document_public_id' => $document->public_id,
                'document_type' => $document->document_type->value,
            ],
        );

        $extension = $document->mime_type === 'application/pdf' ? 'pdf' : 'webp';

        return [
            'bytes' => $bytes,
            'mime_type' => $document->mime_type,
            'filename' => "kyc-{$document->public_id}.{$extension}",
        ];
    }

    public function deleteKycDocument(User $actor, Business $business, KycDocument $document): void
    {
        $diskName = $document->disk;
        $storageKey = $document->storage_key;
        $docPublicId = $document->public_id;
        $docType = $document->document_type->value;

        DB::transaction(function () use ($actor, $business, $document, $docPublicId, $docType): void {
            $document->delete();

            $this->auditLogger->record(
                actor: $actor,
                action: 'kyc.document_deleted',
                auditable: $business,
                oldValues: [
                    'kyc_document_public_id' => $docPublicId,
                    'document_type' => $docType,
                ],
            );
        });

        try {
            Storage::disk($diskName)->delete($storageKey);
        } catch (Throwable) {
            // Best-effort private file removal.
        }
    }
}
