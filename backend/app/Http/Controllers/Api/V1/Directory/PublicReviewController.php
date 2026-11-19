<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Directory;

use App\Domains\Directory\Models\Business;
use App\Domains\Reviews\Enums\ReviewModerationStatus;
use App\Domains\Reviews\Enums\ReviewStatus;
use App\Domains\Reviews\Exceptions\ReviewException;
use App\Domains\Reviews\Models\Review;
use App\Domains\Reviews\Services\ReviewManagementService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Reviews\ReportReviewRequest;
use App\Http\Requests\Api\V1\Reviews\SubmitReviewRequest;
use App\Http\Requests\Api\V1\Reviews\VerifyReviewOtpRequest;
use App\Http\Resources\Api\V1\Reviews\PublicReviewResource;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PublicReviewController extends Controller
{
    public function __construct(
        private readonly ReviewManagementService $reviewService,
    ) {}

    public function index(Request $request, string $citySlug, string $businessSlug): JsonResponse
    {
        $business = Business::query()
            ->published()
            ->where('slug', $businessSlug)
            ->whereHas('city', static fn ($q) => $q->where('slug', $citySlug)->where('is_active', true))
            ->firstOrFail();

        return $this->getReviewsResponse($request, $business);
    }

    public function indexByBusiness(Request $request, Business $business): JsonResponse
    {
        return $this->getReviewsResponse($request, $business);
    }

    private function getReviewsResponse(Request $request, Business $business): JsonResponse
    {
        // 1. Rating distribution breakdown
        $counts = Review::query()
            ->where('business_id', $business->id)
            ->where('status', ReviewStatus::Published->value)
            ->where('moderation_status', ReviewModerationStatus::Approved->value)
            ->select('rating', DB::raw('count(*) as aggregate'))
            ->groupBy('rating')
            ->pluck('aggregate', 'rating')
            ->all();

        $breakdown = [
            '5' => (int) ($counts[5] ?? 0),
            '4' => (int) ($counts[4] ?? 0),
            '3' => (int) ($counts[3] ?? 0),
            '2' => (int) ($counts[2] ?? 0),
            '1' => (int) ($counts[1] ?? 0),
        ];

        // 2. Filterable review query
        $query = Review::query()
            ->where('business_id', $business->id)
            ->published();

        if ($request->filled('rating')) {
            $query->where('rating', (int) $request->query('rating'));
        }

        $sort = (string) $request->query('sort', 'newest');
        match ($sort) {
            'highest' => $query->orderByDesc('rating')->orderByDesc('id'),
            'lowest' => $query->orderBy('rating')->orderByDesc('id'),
            default => $query->orderByDesc('id'),
        };

        $perPage = min(30, max(1, (int) $request->query('per_page', 10)));
        $reviews = $query->cursorPaginate($perPage);

        return ApiResponse::paginated(
            $reviews,
            PublicReviewResource::collection($reviews->items())->resolve($request),
            [
                'rating_avg' => (float) $business->rating_avg,
                'review_count' => (int) $business->review_count,
                'breakdown' => $breakdown,
            ]
        );
    }

    public function store(SubmitReviewRequest $request, string $citySlug, string $businessSlug): JsonResponse
    {
        $business = Business::query()
            ->published()
            ->where('slug', $businessSlug)
            ->whereHas('city', static fn ($q) => $q->where('slug', $citySlug)->where('is_active', true))
            ->firstOrFail();

        return $this->executeStore($request, $business);
    }

    public function storeByBusiness(SubmitReviewRequest $request, Business $business): JsonResponse
    {
        return $this->executeStore($request, $business);
    }

    private function executeStore(SubmitReviewRequest $request, Business $business): JsonResponse
    {
        try {
            $result = $this->reviewService->submitReview(
                $business,
                $request->validated(),
                $request->user('sanctum'),
                (string) $request->ip()
            );
        } catch (ReviewException $e) {
            return ApiResponse::error($e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
        }

        if ($result['requires_otp']) {
            return ApiResponse::created([
                'review' => (new PublicReviewResource($result['review']))->resolve($request),
                'requires_otp' => true,
                'challenge_id' => $result['challenge_id'] ?? null,
                'expires_in_seconds' => $result['expires_in_seconds'] ?? null,
                'resend_after_seconds' => $result['resend_after_seconds'] ?? null,
            ], ['message' => 'Verification code sent to your phone.']);
        }

        return ApiResponse::created([
            'review' => (new PublicReviewResource($result['review']))->resolve($request),
            'requires_otp' => false,
        ], ['message' => 'Your review has been verified and published.']);
    }

    public function verifyOtp(VerifyReviewOtpRequest $request, Review $review): JsonResponse
    {
        try {
            $verified = $this->reviewService->verifyGuestReviewOtp(
                $review,
                (string) $request->validated()['code'],
                (string) $request->ip()
            );
        } catch (ReviewException $e) {
            return ApiResponse::error($e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
        } catch (\App\Domains\Identity\Exceptions\IdentityException $ie) {
            return ApiResponse::error($ie->errorCode, $ie->getMessage(), $ie->httpStatus, $ie->details);
        }

        return ApiResponse::success(
            (new PublicReviewResource($verified))->resolve($request),
            ['message' => 'Review verified and published successfully.']
        );
    }

    public function report(ReportReviewRequest $request, Review $review): JsonResponse
    {
        try {
            $this->reviewService->reportReview(
                $review,
                $request->validated(),
                $request->user('sanctum'),
                (string) $request->ip()
            );
        } catch (ReviewException $e) {
            return ApiResponse::error($e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
        }

        return ApiResponse::success(
            null,
            ['message' => 'Thank you. The review has been reported for moderation review.']
        );
    }
}
