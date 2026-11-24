<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Account;

use App\Domains\Directory\Services\SavedBusinessService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Directory\PublicBusinessResource;
use App\Models\User;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class SavedBusinessController extends Controller
{
    public function __construct(
        private readonly SavedBusinessService $savedBusinessService,
    ) {}

    /**
     * List paginated saved businesses for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $perPage = (int) $request->query('per_page', 15);

        $paginated = $this->savedBusinessService->getSavedBusinesses($user, $perPage);

        return ApiResponse::paginated(
            $paginated,
            PublicBusinessResource::collection($paginated->items())->resolve($request)
        );
    }

    /**
     * Retrieve a lightweight array of bookmarked business public ULIDs for UI badges.
     */
    public function ids(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $savedIds = $this->savedBusinessService->getSavedBusinessPublicIds($user);

        return ApiResponse::success([
            'saved_ids' => $savedIds,
        ]);
    }

    /**
     * Save a business to the user's bookmarks (idempotent).
     */
    public function save(Request $request, string $business): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $savedBusiness = $this->savedBusinessService->saveBusiness($user, $business);

        return ApiResponse::success(
            [
                'saved' => true,
                'public_id' => $savedBusiness->public_id,
            ],
            ['message' => 'Business saved successfully.'],
            201
        );
    }

    /**
     * Remove a business from the user's bookmarks (idempotent).
     */
    public function destroy(Request $request, string $business): Response
    {
        /** @var User $user */
        $user = $request->user();

        $this->savedBusinessService->unsaveBusiness($user, $business);

        return response()->noContent();
    }
}
