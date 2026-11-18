<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Media;

use App\Domains\Directory\Enums\VerificationDocumentType;
use App\Domains\Directory\Models\Business;
use App\Domains\Media\Models\KycDocument;
use App\Domains\Media\Services\KycDocumentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Media\UploadKycDocumentRequest;
use App\Http\Resources\Api\V1\Media\KycDocumentResource;
use App\Models\User;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

final class KycDocumentController extends Controller
{
    public function __construct(
        private readonly KycDocumentService $kycService,
    ) {}

    public function index(Request $request, Business $business): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasPermission('kyc.review')) {
            Gate::forUser($user)->authorize('submitVerification', $business);
        }

        $documents = $business->kycDocuments()->get();

        return ApiResponse::success(KycDocumentResource::collection($documents)->resolve($request));
    }

    public function store(UploadKycDocumentRequest $request, Business $business): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::forUser($user)->authorize('submitVerification', $business);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $validated = $request->validated();

        $document = $this->kycService->uploadKycDocument(
            actor: $user,
            business: $business,
            file: $file,
            documentType: VerificationDocumentType::from((string) $validated['document_type']),
            documentNumber: isset($validated['document_number']) ? (string) $validated['document_number'] : null,
        );

        return ApiResponse::created((new KycDocumentResource($document))->resolve($request));
    }

    public function signedUrl(Request $request, Business $business, KycDocument $kycDocument): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::forUser($user)->authorize('view', [$kycDocument, $business]);

        $signed = $this->kycService->issueSignedDownloadUrl($user, $kycDocument);

        return ApiResponse::success($signed);
    }

    public function download(Request $request, KycDocument $kycDocument): Response|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Enforce actor-binding on the signed URL so a valid URL cannot be replayed by another authenticated user.
        if ((string) $request->query('actor') !== $user->public_id) {
            return ApiResponse::error(
                code: 'FORBIDDEN',
                message: 'You are not authorized to use this signed download link.',
                status: Response::HTTP_FORBIDDEN,
            );
        }

        // Re-check live DB authorization independently of Sanctum abilities and URL signature.
        Gate::forUser($user)->authorize('download', $kycDocument);

        $file = $this->kycService->readDocumentForDownload($user, $kycDocument);

        return response($file['bytes'], Response::HTTP_OK, [
            'Content-Type' => $file['mime_type'],
            'Content-Disposition' => 'inline; filename="'.$file['filename'].'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request, Business $business, KycDocument $kycDocument): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::forUser($user)->authorize('delete', [$kycDocument, $business]);

        $this->kycService->deleteKycDocument($user, $business, $kycDocument);

        return ApiResponse::success(['deleted' => true]);
    }
}
