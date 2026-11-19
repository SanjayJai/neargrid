<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Models\BusinessVerification;
use App\Domains\Directory\Services\BusinessVerificationAndClaimService;
use App\Domains\Media\Models\KycDocument;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ReviewVerificationRequest;
use App\Http\Resources\Api\V1\Admin\AdminVerificationResource;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

final class AdminVerificationController extends Controller
{
    public function __construct(
        private readonly BusinessVerificationAndClaimService $verificationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAdmin', BusinessVerification::class);

        $query = BusinessVerification::query()
            ->with(['business.city', 'submittedBy']);

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        if ($request->filled('document_type')) {
            $query->where('document_type', (string) $request->query('document_type'));
        }

        if ($request->filled('business_id')) {
            $query->where('business_id', (int) $request->query('business_id'));
        }

        $verifications = $query->orderBy('created_at')->paginate(25);

        return ApiResponse::paginated(
            $verifications,
            AdminVerificationResource::collection($verifications)->resolve($request)
        );
    }

    public function signedUrl(Request $request, Business $business, KycDocument $kycDocument): JsonResponse
    {
        Gate::authorize('reviewAdmin', BusinessVerification::class);

        if ((int) $kycDocument->business_id !== (int) $business->id) {
            abort(404, 'KYC document does not belong to specified business.');
        }

        $expiresAt = now()->addSeconds(60);
        $url = URL::temporarySignedRoute(
            'api.v1.kyc.download',
            $expiresAt,
            [
                'kycDocument' => $kycDocument->public_id,
                'actor' => $request->user()->id,
            ]
        );

        return ApiResponse::success([
            'download_url' => $url,
            'expires_at' => $expiresAt->toIso8601String(),
            'expires_in_seconds' => 60,
        ]);
    }

    public function review(ReviewVerificationRequest $request, BusinessVerification $verification): JsonResponse
    {
        Gate::authorize('reviewAdmin', $verification);

        $data = $request->validated();
        $decision = (string) $data['decision'];
        $rejectionReason = isset($data['rejection_reason']) ? (string) $data['rejection_reason'] : null;
        $grantTrustBadge = (bool) ($data['grant_trust_badge'] ?? false);

        $reviewed = $this->verificationService->reviewVerification(
            admin: $request->user(),
            verification: $verification,
            decision: $decision,
            rejectionReason: $rejectionReason,
            grantTrustBadge: $grantTrustBadge,
        );

        $reviewed->load(['business.city', 'submittedBy']);

        return ApiResponse::success(
            (new AdminVerificationResource($reviewed))->resolve($request),
            ['message' => "Verification has been {$decision} successfully."]
        );
    }
}
