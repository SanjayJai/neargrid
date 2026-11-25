<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Leads;

use App\Domains\Directory\Models\Business;
use App\Domains\Domains\Services\DomainResolutionService;
use App\Domains\Leads\Models\Enquiry;
use App\Domains\Leads\Services\EnquiryManagementService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Leads\RecordContactRevealRequest;
use App\Http\Requests\Api\V1\Leads\StoreEnquiryRequest;
use App\Http\Requests\Api\V1\Leads\VerifyEnquiryOtpRequest;
use App\Http\Resources\Api\V1\Leads\EnquiryResource;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PublicEnquiryController extends Controller
{
    public function __construct(
        private readonly EnquiryManagementService $enquiryService,
        private readonly DomainResolutionService $domainResolver,
    ) {}

    public function store(StoreEnquiryRequest $request): JsonResponse
    {
        $resolvedDomain = $this->domainResolver->resolveFromRequest($request);
        $effectiveHost = $this->domainResolver->resolveEffectiveHost($request);
        $normalizedHost = $resolvedDomain?->hostname
            ?? $this->domainResolver->normalizeSafeHost($effectiveHost);

        $rawEntry = $this->domainResolver->isTrustedBffRequest($request)
            ? ($request->header('X-Entry-Path') ?? $request->headers->get('referer'))
            : $request->headers->get('referer');

        $result = $this->enquiryService->createEnquiry(
            $request->validated(),
            $request->user('sanctum'),
            $request->ip(),
            [
                'source_domain_id' => $resolvedDomain?->id,
                'source_host' => $normalizedHost,
                'entry_path' => $this->domainResolver->normalizeEntryPath(is_string($rawEntry) ? $rawEntry : null),
            ],
        );

        if ($result['requires_otp']) {
            return ApiResponse::accepted(
                data: [
                    'public_id' => $result['enquiry']->public_id,
                    'status' => 'pending_otp',
                    'requires_otp' => true,
                    'challenge_id' => $result['challenge_id'] ?? null,
                    'expires_in_seconds' => $result['expires_in_seconds'] ?? 300,
                    'resend_after_seconds' => $result['resend_after_seconds'] ?? 45,
                ],
                meta: ['message' => 'OTP challenge sent to verify mobile number.']
            );
        }

        $result['enquiry']->load(['targetBusiness', 'category', 'city', 'locality', 'recipients.business']);

        return ApiResponse::created(
            (new EnquiryResource($result['enquiry']))->resolve($request)
        );
    }

    public function verifyOtp(VerifyEnquiryOtpRequest $request, Enquiry $enquiry): JsonResponse
    {
        $result = $this->enquiryService->verifyEnquiryOtp(
            $enquiry,
            $request->validated()['otp'],
            $request->ip(),
            $request->validated()['device_name'] ?? null
        );

        $result['enquiry']->load(['targetBusiness', 'category', 'city', 'locality', 'recipients.business']);

        return ApiResponse::success([
            'enquiry' => (new EnquiryResource($result['enquiry']))->resolve($request),
            'auth' => [
                'token_type' => $result['auth']['token_type'],
                'access_token' => $result['auth']['access_token'],
                'session_id' => $result['auth']['session_id'],
                'expires_at' => $result['auth']['expires_at'],
                'user' => [
                    'public_id' => $result['auth']['user']->public_id,
                    'name' => $result['auth']['user']->name,
                    'phone_e164' => $result['auth']['user']->phone_e164,
                ],
            ],
        ]);
    }

    public function contactReveal(RecordContactRevealRequest $request, Business $business): JsonResponse
    {
        $this->enquiryService->recordContactReveal(
            $business,
            $request->user('sanctum'),
            (string) ($request->validated()['channel'] ?? 'phone'),
            $request->ip()
        );

        return ApiResponse::success([
            'business_public_id' => $business->public_id,
            'revealed' => true,
        ]);
    }

    public function customerEnquiries(Request $request): JsonResponse
    {
        $user = $request->user();

        $enquiries = Enquiry::query()
            ->where('user_id', $user->id)
            ->with(['recipients.business', 'category', 'city', 'locality', 'targetBusiness'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return ApiResponse::paginated(
            $enquiries,
            EnquiryResource::collection($enquiries)->resolve($request)
        );
    }
}
