<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Models\Organization;
use App\Domains\Directory\Models\OrganizationMember;
use App\Models\User;

final class OrganizationAccessPolicy
{
    public function view(User $actor, Organization $organization): bool
    {
        return $this->activeMembership($actor, $organization) !== null
            || $this->isOrgOwner($actor, $organization);
    }

    public function update(User $actor, Organization $organization): bool
    {
        return $this->hasCorporateRole($actor, $organization, ['org_owner', 'corporate_admin']);
    }

    public function manageMembers(User $actor, Organization $organization): bool
    {
        return $this->hasCorporateRole($actor, $organization, ['org_owner', 'corporate_admin']);
    }

    public function attachBranch(User $actor, Organization $organization): bool
    {
        return $this->hasCorporateRole($actor, $organization, ['org_owner', 'corporate_admin']);
    }

    public function viewLeads(User $actor, Organization $organization): bool
    {
        return $this->view($actor, $organization);
    }

    /**
     * Evaluates whether an organization member (NOT direct business owner/member)
     * may access a branch for a given capability.
     *
     * Capabilities: 'view', 'profile.edit', 'hours.edit', 'services.edit',
     * 'media.manage', 'reviews', 'kyc', 'leads', 'staff'
     */
    public function canAccessBranch(User $actor, Business $branch, string $capability = 'view'): bool
    {
        if ($actor->status !== 'active' || $branch->organization_id === null) {
            return false;
        }

        $organization = $branch->organization;
        if ($organization === null || $organization->trashed() || $organization->status !== 'active') {
            return false;
        }

        // Strict franchise isolation:
        // Org members NEVER access franchise KYC, profile edits, reviews, or staff.
        // Franchise leads require explicit branch-level allow_hq_lead_access === true.
        if ($branch->branch_type === 'franchise') {
            if ($capability !== 'leads' || ! $branch->allow_hq_lead_access) {
                return false;
            }
        }

        if (! in_array($branch->branch_type, ['headquarters', 'company_owned', 'franchise'], true)) {
            return false;
        }

        if ($this->isOrgOwner($actor, $organization)) {
            return true;
        }

        $member = $this->activeMembership($actor, $organization);
        if ($member === null) {
            return false;
        }

        if (in_array($member->role, ['org_owner', 'corporate_admin'], true)) {
            if ($member->scope_type === 'assigned_cities') {
                return $this->cityInScope($member, (int) $branch->city_id);
            }

            return true;
        }

        if ($member->role === 'regional_manager') {
            // Regional managers cannot manage staff on company branches
            if ($capability === 'staff') {
                return false;
            }

            return $this->cityInScope($member, (int) $branch->city_id);
        }

        return false;
    }

    public function activeMembership(User $actor, Organization $organization): ?OrganizationMember
    {
        if ($actor->status !== 'active' || $organization->trashed() || $organization->status !== 'active') {
            return null;
        }

        return OrganizationMember::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $actor->id)
            ->where('status', 'active')
            ->first();
    }

    private function isOrgOwner(User $actor, Organization $organization): bool
    {
        return $actor->status === 'active'
            && ! $organization->trashed()
            && $organization->status === 'active'
            && $organization->owner_user_id !== null
            && (int) $organization->owner_user_id === (int) $actor->id;
    }

    /**
     * @param  list<string>  $roles
     */
    private function hasCorporateRole(User $actor, Organization $organization, array $roles): bool
    {
        if ($this->isOrgOwner($actor, $organization)) {
            return true;
        }

        $member = $this->activeMembership($actor, $organization);

        return $member !== null && in_array($member->role, $roles, true);
    }

    private function cityInScope(OrganizationMember $member, int $cityId): bool
    {
        $cities = is_array($member->scope_city_ids)
            ? array_map('intval', $member->scope_city_ids)
            : [];

        return in_array($cityId, $cities, true);
    }
}
