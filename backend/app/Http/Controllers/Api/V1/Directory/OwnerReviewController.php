<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Directory;

use App\Domains\Directory\Models\Business;
use App\Domains\Reviews\Exceptions\ReviewException;
use App\Domains\Reviews\Models\Review;
use App\Domains\Reviews\Services\ReviewManagementService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Reviews\ListOwnerReviewsRequest;
use App\Http\Requests\Api\V1\Reviews\OwnerReviewReplyRequest;
use App\Http\Resources\Api\V1\Reviews\OwnerReviewResource;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class OwnerReviewController extends Controller
{
    public function __construct(
        private readonly ReviewManagementService $reviewService,
    ) {}

    public function index(ListOwnerReviewsRequest $request, Business $business): JsonResponse
    {
        Gate::authorize('viewReviews', $business);

        $query = Review::query()
            ->where('business_id', $business->id)
            ->with(['ownerReplyUser']);

        if ($request->filled('rating')) {
            $query->where('rating', (int) $request->query('rating'));
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        if ($request->filled('has_reply')) {
            $hasReply = filter_var($request->query('has_reply'), FILTER_VALIDATE_BOOLEAN);
            if ($hasReply) {
                $query->whereNotNull('owner_reply_text')->where('owner_reply_text', '!=', '');
            } else {
                $query->where(static function ($q): void {
                    $q->whereNull('owner_reply_text')->orWhere('owner_reply_text', '');
                });
            }
        }

        $perPage = min(50, max(1, (int) $request->query('per_page', 15)));
        $reviews = $query->orderByDesc('id')->cursorPaginate($perPage);

        return ApiResponse::paginated(
            $reviews,
            OwnerReviewResource::collection($reviews->items())->resolve($request)
        );
    }

    public function reply(OwnerReviewReplyRequest $request, Business $business, Review $review): JsonResponse
    {
        Gate::authorize('manageReviews', $business);

        // Strict cross-business IDOR prevention
        if ($review->business_id !== $business->id) {
            return ApiResponse::error(
                'REVIEW_NOT_FOUND',
                'The specified review does not belong to this business.',
                Response::HTTP_NOT_FOUND
            );
        }

        try {
            $updated = $this->reviewService->addOrUpdateOwnerReply(
                $review,
                $business,
                (string) $request->validated()['reply_text'],
                $request->user()
            );
        } catch (ReviewException $e) {
            return ApiResponse::error($e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
        }

        $updated->load(['ownerReplyUser']);

        return ApiResponse::success(
            (new OwnerReviewResource($updated))->resolve($request),
            ['message' => 'Review reply saved successfully.']
        );
    }

    public function deleteReply(Business $business, Review $review): JsonResponse
    {
        Gate::authorize('manageReviews', $business);

        // Strict cross-business IDOR prevention
        if ($review->business_id !== $business->id) {
            return ApiResponse::error(
                'REVIEW_NOT_FOUND',
                'The specified review does not belong to this business.',
                Response::HTTP_NOT_FOUND
            );
        }

        try {
            $this->reviewService->deleteOwnerReply($review, $business);
        } catch (ReviewException $e) {
            return ApiResponse::error($e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
        }

        return ApiResponse::success(
            null,
            ['message' => 'Review reply deleted successfully.']
        );
    }
}
