<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Directory;

use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Services\BusinessVerificationAndClaimService;
use App\Domains\Domains\Services\DomainResolutionService;
use App\Domains\Seo\Services\RedirectLifecycleService;
use App\Domains\Seo\Services\SeoPayloadResolver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Directory\SubmitClaimRequest;
use App\Http\Resources\Api\V1\Directory\BusinessClaimResource;
use App\Http\Resources\Api\V1\Directory\PublicBusinessResource;
use App\Models\User;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class PublicBusinessController extends Controller
{
    public function __construct(
        private readonly BusinessVerificationAndClaimService $verificationAndClaim,
        private readonly DomainResolutionService $domainResolver,
        private readonly RedirectLifecycleService $redirectService,
        private readonly SeoPayloadResolver $seoResolver,
    ) {}

    public function show(Request $request, string $citySlug, string $businessSlug): JsonResponse
    {
        $locale = (string) $request->query('locale', 'en');
        $locales = array_values(array_unique([$locale, 'en']));
        $domain = $this->domainResolver->resolveFromRequest($request);

        $business = Business::query()
            ->published()
            ->where('slug', $businessSlug)
            ->whereHas('city', static fn ($q) => $q->where('slug', $citySlug)->where('is_active', true))
            ->with([
                'canonicalDomain',
                'city.translations' => static fn ($q) => $q->whereIn('locale', $locales),
                'locality.translations' => static fn ($q) => $q->whereIn('locale', $locales),
                'primaryCategory.translations' => static fn ($q) => $q->whereIn('locale', $locales),
                'categories.translations' => static fn ($q) => $q->whereIn('locale', $locales),
                'hours',
                'services',
                'amenities',
                'paymentMethods',
                'faqs',
                'attributeValues.definition',
                'media' => static fn ($q) => $q->publicApproved(),
            ])
            ->first();

        if ($business === null) {
            $targetPath = $this->redirectService->resolvePath("/{$citySlug}/business/{$businessSlug}", $domain?->id);

            if ($targetPath !== null) {
                return ApiResponse::error(
                    code: 'MOVED_PERMANENTLY',
                    message: 'This business profile has moved to a new URL.',
                    status: Response::HTTP_MOVED_PERMANENTLY,
                    details: ['target_path' => $targetPath],
                    headers: ['Location' => $targetPath],
                );
            }

            return ApiResponse::error(
                code: 'NOT_FOUND',
                message: 'Business profile not found.',
                status: Response::HTTP_NOT_FOUND,
            );
        }

        $payload = (new PublicBusinessResource($business))->resolve($request);
        $payload['seo'] = $this->seoResolver->resolveBusinessProfile($business, $locale, $domain?->id);

        return ApiResponse::success($payload);
    }

    public function submitClaim(SubmitClaimRequest $request, Business $business): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::forUser($user)->authorize('claim', $business);

        $validated = $request->validated();
        $claim = $this->verificationAndClaim->submitClaim(
            claimant: $user,
            business: $business,
            proofFilePathPrivate: (string) $validated['proof_file_path_private'],
            claimNotes: isset($validated['claim_notes']) ? (string) $validated['claim_notes'] : null,
        );

        return ApiResponse::created((new BusinessClaimResource($claim))->resolve($request));
    }
}
