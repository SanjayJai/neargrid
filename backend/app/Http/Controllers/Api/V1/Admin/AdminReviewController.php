<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domains\Reviews\Enums\ReviewModerationStatus;
use App\Domains\Reviews\Models\Review;
use App\Domains\Reviews\Services\ReviewManagementService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Reviews\ModerateReviewRequest;
use App\Http\Resources\Api\V1\Reviews\AdminReviewResource;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class AdminReviewController extends Controller
{
    public function __construct(
        private readonly ReviewManagementService $reviewService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAdmin', Review::class);

        $query = Review::query()->with(['business', 'ownerReplyUser', 'moderatedByAdmin', 'reports']);

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        if ($request->filled('moderation_status')) {
            $query->where('moderation_status', (string) $request->query('moderation_status'));
        }

        if ($request->filled('rating')) {
            $query->where('rating', (int) $request->query('rating'));
        }

        if ($request->filled('business_id')) {
            $query->where('business_id', (int) $request->query('business_id'));
        }

        if ($request->boolean('flagged_only')) {
            $query->where('moderation_status', ReviewModerationStatus::Flagged->value);
        }

        $reviews = $query->orderByDesc('created_at')->paginate(25);

        return ApiResponse::paginated(
            $reviews,
            AdminReviewResource::collection($reviews)->resolve($request)
        );
    }

    public function show(Request $request, Review $review): JsonResponse
    {
        Gate::authorize('viewAdmin', Review::class);

        $review->load(['business', 'ownerReplyUser', 'moderatedByAdmin', 'reports']);

        return ApiResponse::success(
            (new AdminReviewResource($review))->resolve($request)
        );
    }

    public function moderate(ModerateReviewRequest $request, Review $review): JsonResponse
    {
        Gate::authorize('moderateAdmin', $review);

        $data = $request->validated();
        $targetModerationStatus = ReviewModerationStatus::from($data['status']);

        $moderated = $this->reviewService->moderateReview(
            $review,
            $targetModerationStatus,
            isset($data['reason']) ? (string) $data['reason'] : null,
            $request->user()
        );

        $moderated->load(['business', 'ownerReplyUser', 'moderatedByAdmin', 'reports']);

        return ApiResponse::success(
            (new AdminReviewResource($moderated))->resolve($request),
            ['message' => 'Review moderation status updated successfully.']
        );
    }
}
