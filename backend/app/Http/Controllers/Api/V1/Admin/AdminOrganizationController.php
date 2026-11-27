<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domains\Directory\Exceptions\OrganizationException;
use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Models\Organization;
use App\Domains\Directory\Models\OrganizationMember;
use App\Domains\Directory\Services\OrganizationService;
use App\Domains\Governance\Models\AuditLog;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class AdminOrganizationController extends Controller
{
    public function __construct(
        private readonly OrganizationService $organizationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAdmin', AuditLog::class);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', Rule::in(['active', 'suspended'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);

        $query = Organization::query()
            ->with(['owner:id,public_id,name,email'])
            ->withCount(['branches', 'members', 'domains']);

        if (! empty($validated['q'])) {
            $term = trim((string) $validated['q']);
            $query->where(static function ($q) use ($term): void {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('slug', 'like', "%{$term}%");
            });
        }

        if (! empty($validated['status'])) {
            $query->where('status', (string) $validated['status']);
        }

        $orgs = $query->orderByDesc('created_at')->paginate((int) ($validated['per_page'] ?? 20));

        return ApiResponse::paginated(
            $orgs,
            collect($orgs->items())->map(fn (Organization $org): array => $this->serializeSummary($org))->values()->all()
        );
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('viewAdmin', AuditLog::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:140'],
            'slug' => ['nullable', 'string', 'max:140'],
            'owner_public_id' => ['required', 'string', 'exists:users,public_id'],
        ]);

        $owner = User::query()->where('public_id', $validated['owner_public_id'])->firstOrFail();

        $org = $this->organizationService->createOrganization(
            owner: $owner,
            data: [
                'name' => (string) $validated['name'],
                'slug' => $validated['slug'] ?? null,
            ],
            ipAddress: $request->ip(),
        );

        $org->load('owner:id,public_id,name,email')->loadCount(['branches', 'members', 'domains']);

        return ApiResponse::created(
            $this->serializeSummary($org),
            ['message' => 'Organization created successfully.']
        );
    }

    public function show(Request $request, Organization $organization): JsonResponse
    {
        Gate::authorize('viewAdmin', AuditLog::class);

        $organization->load([
            'owner:id,public_id,name,email',
            'branches.city:id,public_id,name,slug',
            'branches.primaryCategory:id,public_id,name,slug',
            'members.user:id,public_id,name,email',
            'domains:id,public_id,organization_id,hostname,status,verification_status,ssl_status,is_primary',
        ]);

        return ApiResponse::success([
            'public_id' => $organization->public_id,
            'name' => $organization->name,
            'slug' => $organization->slug,
            'status' => $organization->status,
            'owner' => $organization->owner ? [
                'public_id' => $organization->owner->public_id,
                'name' => $organization->owner->name,
                'email' => $organization->owner->email,
            ] : null,
            'branches' => $organization->branches->map(static fn (Business $b): array => [
                'public_id' => $b->public_id,
                'name' => $b->name,
                'slug' => $b->slug,
                'status' => $b->status?->value ?? (string) $b->status,
                'branch_type' => $b->branch_type,
                'franchise_code' => $b->franchise_code,
                'allow_hq_lead_access' => (bool) $b->allow_hq_lead_access,
                'city_name' => $b->city?->name,
                'category_name' => $b->primaryCategory?->name,
            ])->values()->all(),
            'members' => $organization->members->map(static fn (OrganizationMember $m): array => [
                'public_id' => $m->public_id,
                'role' => $m->role,
                'scope_type' => $m->scope_type,
                'scope_city_ids' => $m->scope_city_ids ?? [],
                'status' => $m->status,
                'user' => $m->user ? [
                    'public_id' => $m->user->public_id,
                    'name' => $m->user->name,
                    'email' => $m->user->email,
                ] : null,
            ])->values()->all(),
            'domains' => $organization->domains->map(static fn ($d): array => [
                'public_id' => $d->public_id,
                'hostname' => $d->hostname,
                'status' => $d->status,
                'verification_status' => $d->verification_status,
                'ssl_status' => $d->ssl_status,
                'is_primary' => (bool) $d->is_primary,
            ])->values()->all(),
            'created_at' => $organization->created_at?->toIso8601String(),
        ]);
    }

    public function update(Request $request, Organization $organization): JsonResponse
    {
        Gate::authorize('viewAdmin', AuditLog::class);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:140'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'suspended'])],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $updated = $this->organizationService->updateOrganization($actor, $organization, $validated, $request->ip());
        $updated->load('owner:id,public_id,name,email')->loadCount(['branches', 'members', 'domains']);

        return ApiResponse::success(
            $this->serializeSummary($updated),
            ['message' => 'Organization updated successfully.']
        );
    }

    public function attachBranch(Request $request, Organization $organization): JsonResponse
    {
        Gate::authorize('viewAdmin', AuditLog::class);

        $validated = $request->validate([
            'business_public_id' => ['required', 'string', 'exists:businesses,public_id'],
            'branch_type' => ['required', 'string', Rule::in(['headquarters', 'company_owned', 'franchise'])],
            'franchise_code' => ['nullable', 'string', 'max:64'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $branch = Business::query()->where('public_id', $validated['business_public_id'])->firstOrFail();

        try {
            $attached = $this->organizationService->attachBranch(
                actor: $actor,
                organization: $organization,
                branch: $branch,
                branchType: (string) $validated['branch_type'],
                franchiseCode: $validated['franchise_code'] ?? null,
                ipAddress: $request->ip(),
            );
        } catch (OrganizationException $e) {
            return ApiResponse::error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        return ApiResponse::success([
            'public_id' => $attached->public_id,
            'name' => $attached->name,
            'branch_type' => $attached->branch_type,
            'franchise_code' => $attached->franchise_code,
            'allow_hq_lead_access' => (bool) $attached->allow_hq_lead_access,
        ], ['message' => 'Branch attached to organization.']);
    }

    public function setFranchiseHqLeadAccess(Request $request, Organization $organization, Business $business): JsonResponse
    {
        Gate::authorize('viewAdmin', AuditLog::class);

        if ((int) $business->organization_id !== (int) $organization->id) {
            abort(404, 'Target business is not a branch of this organization.');
        }

        $validated = $request->validate([
            'allow_hq_lead_access' => ['required', 'boolean'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        try {
            $updated = $this->organizationService->setFranchiseHqLeadAccess(
                actor: $actor,
                organization: $organization,
                branch: $business,
                allow: (bool) $validated['allow_hq_lead_access'],
                ipAddress: $request->ip(),
            );
        } catch (OrganizationException $e) {
            return ApiResponse::error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        return ApiResponse::success([
            'public_id' => $updated->public_id,
            'allow_hq_lead_access' => (bool) $updated->allow_hq_lead_access,
        ], ['message' => 'Franchise HQ lead access updated.']);
    }

    public function upsertMember(Request $request, Organization $organization): JsonResponse
    {
        Gate::authorize('viewAdmin', AuditLog::class);

        $validated = $request->validate([
            'user_public_id' => ['required', 'string', 'exists:users,public_id'],
            'role' => ['required', 'string', Rule::in(['org_owner', 'corporate_admin', 'regional_manager'])],
            'scope_type' => ['required', 'string', Rule::in(['all_branches', 'company_owned_only', 'assigned_cities'])],
            'city_public_ids' => ['nullable', 'array'],
            'city_public_ids.*' => ['string'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $targetUser = User::query()->where('public_id', $validated['user_public_id'])->firstOrFail();

        try {
            $member = $this->organizationService->upsertMember(
                actor: $actor,
                organization: $organization,
                targetUser: $targetUser,
                role: (string) $validated['role'],
                scopeType: (string) $validated['scope_type'],
                cityPublicIds: $validated['city_public_ids'] ?? null,
                ipAddress: $request->ip(),
            );
        } catch (OrganizationException $e) {
            return ApiResponse::error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        return ApiResponse::success([
            'public_id' => $member->public_id,
            'role' => $member->role,
            'scope_type' => $member->scope_type,
            'status' => $member->status,
        ], ['message' => 'Organization member saved.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSummary(Organization $org): array
    {
        return [
            'public_id' => $org->public_id,
            'name' => $org->name,
            'slug' => $org->slug,
            'status' => $org->status,
            'owner' => $org->owner ? [
                'public_id' => $org->owner->public_id,
                'name' => $org->owner->name,
                'email' => $org->owner->email,
            ] : null,
            'branches_count' => (int) ($org->branches_count ?? 0),
            'members_count' => (int) ($org->members_count ?? 0),
            'domains_count' => (int) ($org->domains_count ?? 0),
            'created_at' => $org->created_at?->toIso8601String(),
        ];
    }
}
