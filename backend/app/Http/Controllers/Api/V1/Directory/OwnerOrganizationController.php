<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Directory;

use App\Domains\Directory\Exceptions\OrganizationException;
use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Models\Organization;
use App\Domains\Directory\Models\OrganizationMember;
use App\Domains\Directory\Services\OrganizationService;
use App\Domains\Leads\Models\EnquiryRecipient;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Leads\OwnerLeadResource;
use App\Models\User;
use App\Policies\OrganizationAccessPolicy;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class OwnerOrganizationController extends Controller
{
    public function __construct(
        private readonly OrganizationService $organizationService,
        private readonly OrganizationAccessPolicy $orgPolicy,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $orgs = Organization::query()
            ->where('status', 'active')
            ->where(static function ($q) use ($actor): void {
                $q->where('owner_user_id', $actor->id)
                    ->orWhereHas('members', static function ($mq) use ($actor): void {
                        $mq->where('user_id', $actor->id)->where('status', 'active');
                    });
            })
            ->orderBy('name')
            ->get()
            ->map(fn (Organization $org): array => $this->serializeOrganization($org))
            ->values()
            ->all();

        return ApiResponse::success($orgs);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'slug' => ['nullable', 'string', 'max:200'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $org = $this->organizationService->createOrganization($actor, $validated, $request->ip());

        return ApiResponse::created($this->serializeOrganization($org));
    }

    public function show(Organization $organization): JsonResponse
    {
        Gate::authorize('view', $organization);

        return ApiResponse::success($this->serializeOrganization($organization));
    }

    public function update(Request $request, Organization $organization): JsonResponse
    {
        Gate::authorize('update', $organization);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:180'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'suspended'])],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $updated = $this->organizationService->updateOrganization($actor, $organization, $validated, $request->ip());

        return ApiResponse::success($this->serializeOrganization($updated));
    }

    public function indexMembers(Organization $organization): JsonResponse
    {
        Gate::authorize('manageMembers', $organization);

        $members = $organization->members()
            ->with('user')
            ->where('status', 'active')
            ->get()
            ->map(static fn (OrganizationMember $m): array => [
                'public_id' => $m->public_id,
                'user_public_id' => $m->user?->public_id,
                'role' => $m->role,
                'scope_type' => $m->scope_type,
                'scope_city_ids' => $m->scope_city_ids,
                'status' => $m->status,
            ])
            ->values()
            ->all();

        return ApiResponse::success($members);
    }

    public function upsertMember(Request $request, Organization $organization): JsonResponse
    {
        Gate::authorize('manageMembers', $organization);

        $validated = $request->validate([
            'user_public_id' => ['required', 'string', 'size:26'],
            'role' => ['required', 'string', Rule::in(['org_owner', 'corporate_admin', 'regional_manager'])],
            'scope_type' => ['required', 'string', Rule::in(['all_branches', 'company_owned_only', 'assigned_cities'])],
            'city_public_ids' => ['nullable', 'array'],
            'city_public_ids.*' => ['string', 'size:26'],
        ]);

        $targetUser = User::query()->where('public_id', $validated['user_public_id'])->first();
        if ($targetUser === null) {
            return ApiResponse::error('USER_NOT_FOUND', 'Target user not found.', Response::HTTP_NOT_FOUND);
        }

        /** @var User $actor */
        $actor = $request->user();

        try {
            $member = $this->organizationService->upsertMember(
                $actor,
                $organization,
                $targetUser,
                $validated['role'],
                $validated['scope_type'],
                $validated['city_public_ids'] ?? null,
                $request->ip(),
            );
        } catch (OrganizationException $e) {
            return ApiResponse::error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        return ApiResponse::success([
            'public_id' => $member->public_id,
            'user_public_id' => $targetUser->public_id,
            'role' => $member->role,
            'scope_type' => $member->scope_type,
            'scope_city_ids' => $member->scope_city_ids,
            'status' => $member->status,
        ]);
    }

    public function indexBranches(Request $request, Organization $organization): JsonResponse
    {
        Gate::authorize('view', $organization);

        /** @var User $actor */
        $actor = $request->user();

        $branches = $organization->branches()
            ->with('city')
            ->get()
            ->filter(fn (Business $b): bool => $b->isOwnedBy($actor) || $this->orgPolicy->canAccessBranch($actor, $b, 'view'))
            ->map(static fn (Business $b): array => [
                'public_id' => $b->public_id,
                'name' => $b->name,
                'slug' => $b->slug,
                'city_slug' => $b->city?->slug,
                'branch_type' => $b->branch_type,
                'franchise_code' => $b->franchise_code,
                'allow_hq_lead_access' => $b->allow_hq_lead_access,
            ])
            ->values()
            ->all();

        return ApiResponse::success($branches);
    }

    public function attachBranch(Request $request, Organization $organization): JsonResponse
    {
        Gate::authorize('attachBranch', $organization);

        $validated = $request->validate([
            'business_public_id' => ['required', 'string', 'size:26'],
            'branch_type' => ['required', 'string', Rule::in(['headquarters', 'company_owned', 'franchise'])],
            'franchise_code' => ['nullable', 'string', 'max:60'],
        ]);

        $branch = Business::query()->where('public_id', $validated['business_public_id'])->first();
        if ($branch === null) {
            return ApiResponse::error('BUSINESS_NOT_FOUND', 'Business not found.', Response::HTTP_NOT_FOUND);
        }

        /** @var User $actor */
        $actor = $request->user();

        if (! $branch->isOwnedBy($actor) && ! ($actor->status === 'active' && $actor->hasRole('super_admin'))) {
            return ApiResponse::error(
                'BRANCH_OWNERSHIP_REQUIRED',
                'Only the business owner may attach this business to an organization.',
                Response::HTTP_FORBIDDEN,
            );
        }

        try {
            $attached = $this->organizationService->attachBranch(
                $actor,
                $organization,
                $branch,
                $validated['branch_type'],
                $validated['franchise_code'] ?? null,
                $request->ip(),
            );
        } catch (OrganizationException $e) {
            return ApiResponse::error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        return ApiResponse::success([
            'public_id' => $attached->public_id,
            'branch_type' => $attached->branch_type,
            'franchise_code' => $attached->franchise_code,
            'allow_hq_lead_access' => $attached->allow_hq_lead_access,
        ]);
    }

    public function updateFranchiseHqLeadAccess(
        Request $request,
        Organization $organization,
        Business $business,
    ): JsonResponse {
        $validated = $request->validate([
            'allow_hq_lead_access' => ['required', 'boolean'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $updated = $this->organizationService->setFranchiseHqLeadAccess(
                $actor,
                $organization,
                $business,
                (bool) $validated['allow_hq_lead_access'],
                $request->ip(),
            );
        } catch (OrganizationException $e) {
            return ApiResponse::error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        return ApiResponse::success([
            'public_id' => $updated->public_id,
            'branch_type' => $updated->branch_type,
            'allow_hq_lead_access' => $updated->allow_hq_lead_access,
        ]);
    }

    public function indexLeads(Request $request, Organization $organization): JsonResponse
    {
        Gate::authorize('viewLeads', $organization);

        /** @var User $actor */
        $actor = $request->user();

        $allowedBranchIds = $organization->branches()
            ->get()
            ->filter(fn (Business $b): bool => $b->isOwnedBy($actor) || $this->orgPolicy->canAccessBranch($actor, $b, 'leads'))
            ->pluck('id')
            ->all();

        if ($allowedBranchIds === []) {
            return ApiResponse::success([]);
        }

        $leads = EnquiryRecipient::query()
            ->whereIn('business_id', $allowedBranchIds)
            ->with(['enquiry.category', 'enquiry.city', 'enquiry.locality', 'assignedUser'])
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return ApiResponse::success(OwnerLeadResource::collection($leads)->resolve($request));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeOrganization(Organization $organization): array
    {
        return [
            'public_id' => $organization->public_id,
            'name' => $organization->name,
            'slug' => $organization->slug,
            'status' => $organization->status,
            'created_at' => $organization->created_at?->toIso8601String(),
        ];
    }
}
