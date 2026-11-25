<?php

declare(strict_types=1);

namespace App\Domains\Directory\Services;

use App\Domains\Directory\Exceptions\OrganizationException;
use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Models\Organization;
use App\Domains\Directory\Models\OrganizationMember;
use App\Domains\Governance\Services\AuditLogger;
use App\Domains\Location\Models\City;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OrganizationService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array{name: string, slug?: ?string}  $data
     */
    public function createOrganization(User $owner, array $data, ?string $ipAddress = null): Organization
    {
        $baseSlug = isset($data['slug']) && trim((string) $data['slug']) !== ''
            ? Str::slug((string) $data['slug'])
            : Str::slug($data['name']);
        $slug = $baseSlug !== '' ? $baseSlug : 'org-' . Str::lower(Str::random(6));

        if (Organization::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$slug}-" . Str::lower(Str::random(4));
        }

        return DB::transaction(function () use ($owner, $data, $slug, $ipAddress): Organization {
            $org = Organization::query()->create([
                'name' => trim($data['name']),
                'slug' => $slug,
                'owner_user_id' => $owner->id,
                'status' => 'active',
            ]);

            OrganizationMember::query()->create([
                'organization_id' => $org->id,
                'user_id' => $owner->id,
                'role' => 'org_owner',
                'scope_type' => 'all_branches',
                'status' => 'active',
                'invited_by_user_id' => $owner->id,
                'accepted_at' => now(),
            ]);

            $this->auditLogger->record(
                actor: $owner,
                action: 'organization.created',
                auditable: $org,
                newValues: ['name' => $org->name, 'slug' => $org->slug],
                ipAddress: $ipAddress,
            );

            return $org->fresh();
        });
    }

    /**
     * Ordinary organization updates NEVER modify branch allow_hq_lead_access.
     *
     * @param  array{name?: string, status?: string}  $data
     */
    public function updateOrganization(User $actor, Organization $organization, array $data, ?string $ipAddress = null): Organization
    {
        $oldValues = ['name' => $organization->name, 'status' => $organization->status];

        if (isset($data['name'])) {
            $organization->name = trim($data['name']);
        }
        if (isset($data['status']) && in_array($data['status'], ['active', 'suspended'], true)) {
            $organization->status = $data['status'];
        }
        $organization->save();

        $this->auditLogger->record(
            actor: $actor,
            action: 'organization.updated',
            auditable: $organization,
            oldValues: $oldValues,
            newValues: ['name' => $organization->name, 'status' => $organization->status],
            ipAddress: $ipAddress,
        );

        return $organization->fresh();
    }

    /**
     * @param  list<string>|null  $cityPublicIds
     */
    public function upsertMember(
        User $actor,
        Organization $organization,
        User $targetUser,
        string $role,
        string $scopeType,
        ?array $cityPublicIds = null,
        ?string $ipAddress = null,
    ): OrganizationMember {
        if (! in_array($role, ['org_owner', 'corporate_admin', 'regional_manager'], true)) {
            throw new OrganizationException('INVALID_ORG_ROLE', 'Invalid organization role.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (! in_array($scopeType, ['all_branches', 'company_owned_only', 'assigned_cities'], true)) {
            throw new OrganizationException('INVALID_ORG_SCOPE', 'Invalid organization scope type.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Prevent privilege escalation: only an existing org_owner (or active super_admin)
        // may assign the org_owner role, and members cannot self-promote.
        $isSuperAdmin = $actor->status === 'active' && $actor->hasRole('super_admin');
        $actorIsOrgOwner = ((int) $organization->owner_user_id === (int) $actor->id)
            || OrganizationMember::query()
                ->where('organization_id', $organization->id)
                ->where('user_id', $actor->id)
                ->where('status', 'active')
                ->where('role', 'org_owner')
                ->exists();

        if ($role === 'org_owner' && ! $actorIsOrgOwner && ! $isSuperAdmin) {
            throw new OrganizationException(
                'ORG_OWNER_ROLE_REQUIRED',
                'Only an organization owner may assign the org_owner role.',
                Response::HTTP_FORBIDDEN,
            );
        }

        if ((int) $actor->id === (int) $targetUser->id && ! $actorIsOrgOwner && ! $isSuperAdmin) {
            throw new OrganizationException(
                'SELF_ROLE_ESCALATION_FORBIDDEN',
                'Members cannot modify or escalate their own organization role.',
                Response::HTTP_FORBIDDEN,
            );
        }

        $cityIds = null;
        if ($role === 'regional_manager' || $scopeType === 'assigned_cities') {
            $cityIds = City::query()
                ->whereIn('public_id', $cityPublicIds ?? [])
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->values()
                ->all();

            if ($cityIds === []) {
                throw new OrganizationException(
                    'REGIONAL_SCOPE_CITIES_REQUIRED',
                    'Regional managers and city-scoped members require at least one valid city.',
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
        }

        $member = OrganizationMember::query()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'user_id' => $targetUser->id,
            ],
            [
                'role' => $role,
                'scope_type' => $role === 'regional_manager' ? 'assigned_cities' : $scopeType,
                'scope_city_ids' => $cityIds,
                'status' => 'active',
                'invited_by_user_id' => $actor->id,
                'accepted_at' => now(),
            ]
        );

        $this->auditLogger->record(
            actor: $actor,
            action: 'organization.member.upserted',
            auditable: $member,
            newValues: [
                'organization_public_id' => $organization->public_id,
                'target_user_public_id' => $targetUser->public_id,
                'role' => $member->role,
                'scope_type' => $member->scope_type,
                'scope_city_ids' => $member->scope_city_ids,
            ],
            ipAddress: $ipAddress,
        );

        return $member->fresh();
    }

    public function attachBranch(
        User $actor,
        Organization $organization,
        Business $branch,
        string $branchType,
        ?string $franchiseCode = null,
        ?string $ipAddress = null,
    ): Business {
        if (! in_array($branchType, ['headquarters', 'company_owned', 'franchise'], true)) {
            throw new OrganizationException('INVALID_BRANCH_TYPE', 'Invalid branch type.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($branch->organization_id !== null && (int) $branch->organization_id !== (int) $organization->id) {
            throw new OrganizationException(
                'BRANCH_ALREADY_IN_ORGANIZATION',
                'This business is already attached to another organization.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $branch->organization_id = $organization->id;
        $branch->branch_type = $branchType;
        $branch->franchise_code = $branchType === 'franchise' ? ($franchiseCode ? trim($franchiseCode) : null) : null;
        // Franchise branches ALWAYS default to allow_hq_lead_access = false on attach.
        if ($branchType === 'franchise') {
            $branch->allow_hq_lead_access = false;
        }
        $branch->save();

        $this->auditLogger->record(
            actor: $actor,
            action: 'organization.branch.attached',
            auditable: $branch,
            newValues: [
                'organization_public_id' => $organization->public_id,
                'branch_type' => $branch->branch_type,
                'allow_hq_lead_access' => $branch->allow_hq_lead_access,
            ],
            ipAddress: $ipAddress,
        );

        return $branch->fresh();
    }

    /**
     * Explicit, auditable permission path to grant or revoke HQ access to a franchise branch's leads.
     * Only the franchise branch's direct owner (or active super_admin) may toggle this.
     */
    public function setFranchiseHqLeadAccess(
        User $actor,
        Organization $organization,
        Business $branch,
        bool $allow,
        ?string $ipAddress = null,
    ): Business {
        if ((int) $branch->organization_id !== (int) $organization->id || $branch->branch_type !== 'franchise') {
            throw new OrganizationException(
                'NOT_A_FRANCHISE_BRANCH',
                'Target business is not a franchise branch of this organization.',
                Response::HTTP_NOT_FOUND,
            );
        }

        $isBranchOwner = $branch->isOwnedBy($actor);
        $isSuperAdmin = $actor->status === 'active' && $actor->hasRole('super_admin');
        if (! $isBranchOwner && ! $isSuperAdmin) {
            throw new OrganizationException(
                'FRANCHISE_AUTHORIZATION_REQUIRED',
                'Only the franchise branch owner may authorize HQ lead access.',
                Response::HTTP_FORBIDDEN,
            );
        }

        $old = (bool) $branch->allow_hq_lead_access;
        $branch->allow_hq_lead_access = $allow;
        $branch->save();

        $this->auditLogger->record(
            actor: $actor,
            action: 'organization.franchise.hq_lead_access_updated',
            auditable: $branch,
            oldValues: ['allow_hq_lead_access' => $old],
            newValues: ['allow_hq_lead_access' => $allow, 'organization_public_id' => $organization->public_id],
            ipAddress: $ipAddress,
        );

        return $branch->fresh();
    }
}
