<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Directory;

use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Services\BusinessAttributeService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Directory\UpdateBusinessAttributesRequest;
use App\Models\User;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class OwnerBusinessAttributeController extends Controller
{
    public function __construct(
        private readonly BusinessAttributeService $attributes,
    ) {}

    public function show(Request $request, Business $business): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::forUser($user)->authorize('view', $business);

        return ApiResponse::success($this->attributes->getOwnerAttributes($business));
    }

    public function update(UpdateBusinessAttributesRequest $request, Business $business): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::forUser($user)->authorize('update', $business);

        $validated = $request->validated();

        /** @var list<array{attribute_key: string, value: mixed, definition_public_id?: ?string}> $items */
        $items = $validated['attributes'];
        $validateRequired = (bool) ($validated['validate_required'] ?? false);

        $result = $this->attributes->upsertOwnerAttributes(
            actor: $user,
            business: $business,
            items: $items,
            validateRequiredForPublish: $validateRequired,
            ipAddress: $request->ip(),
        );

        return ApiResponse::success($result);
    }

    public function validateForPublish(Request $request, Business $business): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::forUser($user)->authorize('view', $business);

        $this->attributes->validateRequiredAttributesForPublish($business);

        return ApiResponse::success([
            'business_public_id' => $business->public_id,
            'is_complete_for_publish' => true,
        ]);
    }

    public function destroyOrphans(Request $request, Business $business): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::forUser($user)->authorize('update', $business);

        $validated = $request->validate([
            'value_public_ids' => ['sometimes', 'array', 'max:100'],
            'value_public_ids.*' => ['required', 'string', 'size:26'],
        ]);

        /** @var list<string> $valuePublicIds */
        $valuePublicIds = $validated['value_public_ids'] ?? [];
        $deletedCount = $this->attributes->deleteOrphanedAttributes(
            actor: $user,
            business: $business,
            valuePublicIds: $valuePublicIds,
            ipAddress: $request->ip(),
        );

        return ApiResponse::success([
            'deleted_count' => $deletedCount,
            'attributes' => $this->attributes->getOwnerAttributes($business),
        ]);
    }
}
