<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Directory;

use App\Domains\Directory\Enums\BusinessMemberRole;
use App\Domains\Directory\Enums\VerificationDocumentType;
use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Models\BusinessMember;
use App\Domains\Directory\Models\BusinessService;
use App\Domains\Directory\Services\BusinessCompletenessCalculator;
use App\Domains\Directory\Services\BusinessManagementService;
use App\Domains\Directory\Services\BusinessVerificationAndClaimService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Directory\ReplaceBusinessHoursRequest;
use App\Http\Requests\Api\V1\Directory\StoreBusinessMemberRequest;
use App\Http\Requests\Api\V1\Directory\StoreBusinessRequest;
use App\Http\Requests\Api\V1\Directory\SubmitVerificationRequest;
use App\Http\Requests\Api\V1\Directory\UpdateBusinessMemberRequest;
use App\Http\Requests\Api\V1\Directory\UpdateBusinessRequest;
use App\Http\Requests\Api\V1\Directory\UpsertBusinessServiceRequest;
use App\Http\Resources\Api\V1\Directory\BusinessMemberResource;
use App\Http\Resources\Api\V1\Directory\BusinessServiceResource;
use App\Http\Resources\Api\V1\Directory\BusinessVerificationResource;
use App\Http\Resources\Api\V1\Directory\OwnerBusinessResource;
use App\Models\User;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class OwnerBusinessController extends Controller
{
    public function __construct(
        private readonly BusinessManagementService $management,
        private readonly BusinessVerificationAndClaimService $verification,
        private readonly BusinessCompletenessCalculator $completeness,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $businesses = Business::query()
            ->managedBy($user)
            ->with(['primaryCategory', 'categories', 'city', 'locality', 'hours', 'services', 'amenities', 'paymentMethods', 'members'])
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::success(
            OwnerBusinessResource::collection($businesses)->resolve($request)
        );
    }

    public function store(StoreBusinessRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $business = $this->management->createBusiness($user, $request->validated());

        return ApiResponse::created((new OwnerBusinessResource($business))->resolve($request));
    }

    public function show(Request $request, Business $business): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::forUser($user)->authorize('view', $business);

        $business->load(['primaryCategory', 'categories', 'city', 'locality', 'hours', 'services', 'amenities', 'paymentMethods', 'members']);

        return ApiResponse::success((new OwnerBusinessResource($business))->resolve($request));
    }

    public function update(UpdateBusinessRequest $request, Business $business): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::forUser($user)->authorize('update', $business);

        $updated = $this->management->updateBusiness($user, $business, $request->validated());

        return ApiResponse::success((new OwnerBusinessResource($updated))->resolve($request));
    }

    public function updateHours(ReplaceBusinessHoursRequest $request, Business $business): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::forUser($user)->authorize('manageHours', $business);

        /** @var list<array<string, mixed>> $slots */
        $slots = $request->validated()['hours'];
        $updated = $this->management->replaceOpeningHours($user, $business, $slots);

        return ApiResponse::success([
            'business_public_id' => $updated->public_id,
            'completeness_score' => $updated->completeness_score,
            'hours' => $updated->hours->map(static fn ($slot): array => [
                'day_of_week' => $slot->day_of_week,
                'slot_index' => $slot->slot_index,
                'open_time' => $slot->open_time,
                'close_time' => $slot->close_time,
                'is_24_hours' => $slot->is_24_hours,
                'is_closed' => $slot->is_closed,
            ])->values()->all(),
        ]);
    }

    public function storeService(UpsertBusinessServiceRequest $request, Business $business): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::forUser($user)->authorize('manageServices', $business);

        $validated = $request->validated();
        $service = BusinessService::query()->create([
            'business_id' => $business->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'price_min_minor' => $validated['price_min_minor'] ?? null,
            'price_max_minor' => $validated['price_max_minor'] ?? null,
            'currency' => strtoupper((string) ($validated['currency'] ?? 'INR')),
            'price_unit' => $validated['price_unit'] ?? null,
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        $business->completeness_score = $this->completeness->calculate($business);
        $business->save();

        return ApiResponse::created((new BusinessServiceResource($service))->resolve($request));
    }

    public function updateService(
        UpsertBusinessServiceRequest $request,
        Business $business,
        BusinessService $service,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        Gate::forUser($user)->authorize('update', [$service, $business]);

        $validated = $request->validated();
        $service->fill([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'price_min_minor' => $validated['price_min_minor'] ?? null,
            'price_max_minor' => $validated['price_max_minor'] ?? null,
            'currency' => strtoupper((string) ($validated['currency'] ?? $service->currency)),
            'price_unit' => $validated['price_unit'] ?? null,
            'sort_order' => (int) ($validated['sort_order'] ?? $service->sort_order),
            'is_active' => (bool) ($validated['is_active'] ?? $service->is_active),
        ]);
        $service->save();

        return ApiResponse::success((new BusinessServiceResource($service))->resolve($request));
    }

    public function destroyService(
        Request $request,
        Business $business,
        BusinessService $service,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        Gate::forUser($user)->authorize('delete', [$service, $business]);

        $service->delete();
        $business->completeness_score = $this->completeness->calculate($business);
        $business->save();

        return ApiResponse::noContent();
    }

    public function indexMembers(Request $request, Business $business): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::forUser($user)->authorize('manageStaff', $business);

        $members = $business->members()->with('user')->orderBy('created_at')->get();

        return ApiResponse::success(BusinessMemberResource::collection($members)->resolve($request));
    }

    public function storeMember(StoreBusinessMemberRequest $request, Business $business): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::forUser($user)->authorize('manageStaff', $business);

        $validated = $request->validated();
        $member = $this->management->addStaffMember(
            owner: $user,
            business: $business,
            memberUserPublicId: (string) $validated['user_public_id'],
            role: BusinessMemberRole::from((string) $validated['role']),
            permissions: $validated['permissions'] ?? null,
        );

        return ApiResponse::created((new BusinessMemberResource($member))->resolve($request));
    }

    public function updateMember(
        UpdateBusinessMemberRequest $request,
        Business $business,
        BusinessMember $member,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        Gate::forUser($user)->authorize('update', [$member, $business]);

        $validated = $request->validated();
        $updated = $this->management->updateStaffMember(
            owner: $user,
            business: $business,
            member: $member,
            role: BusinessMemberRole::from((string) $validated['role']),
            permissions: $validated['permissions'] ?? $member->permissions,
            status: (string) ($validated['status'] ?? $member->status),
        );

        return ApiResponse::success((new BusinessMemberResource($updated))->resolve($request));
    }

    public function destroyMember(
        Request $request,
        Business $business,
        BusinessMember $member,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        Gate::forUser($user)->authorize('delete', [$member, $business]);

        $this->management->removeStaffMember($user, $business, $member);

        return ApiResponse::noContent();
    }

    public function indexVerifications(Request $request, Business $business): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::forUser($user)->authorize('submitVerification', $business);

        $items = $business->verifications()->get();

        return ApiResponse::success(BusinessVerificationResource::collection($items)->resolve($request));
    }

    public function storeVerification(SubmitVerificationRequest $request, Business $business): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::forUser($user)->authorize('submitVerification', $business);

        $validated = $request->validated();
        $record = $this->verification->submitVerification(
            actor: $user,
            business: $business,
            documentType: VerificationDocumentType::from((string) $validated['document_type']),
            documentNumber: (string) $validated['document_number'],
            privateFilePath: (string) $validated['private_file_path'],
        );

        return ApiResponse::created((new BusinessVerificationResource($record))->resolve($request));
    }
}
