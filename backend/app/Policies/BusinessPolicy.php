<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domains\Directory\Enums\BusinessStatus;
use App\Domains\Directory\Models\Business;
use App\Models\User;

final class BusinessPolicy
{
    public function view(User $actor, Business $business): bool
    {
        if ($business->isOwnedBy($actor)) {
            return true;
        }

        if ($business->activeMemberFor($actor)?->hasCapability('business.view')) {
            return true;
        }

        return $this->orgPolicy()->canAccessBranch($actor, $business, 'view');
    }

    public function update(User $actor, Business $business): bool
    {
        if ($business->isOwnedBy($actor)) {
            return true;
        }

        if ($business->activeMemberFor($actor)?->hasCapability('profile.edit')) {
            return true;
        }

        return $this->orgPolicy()->canAccessBranch($actor, $business, 'profile.edit');
    }

    public function manageHours(User $actor, Business $business): bool
    {
        if ($business->isOwnedBy($actor)) {
            return true;
        }

        if ($business->activeMemberFor($actor)?->hasCapability('hours.edit')) {
            return true;
        }

        return $this->orgPolicy()->canAccessBranch($actor, $business, 'hours.edit');
    }

    public function manageServices(User $actor, Business $business): bool
    {
        if ($business->isOwnedBy($actor)) {
            return true;
        }

        if ($business->activeMemberFor($actor)?->hasCapability('services.edit')) {
            return true;
        }

        return $this->orgPolicy()->canAccessBranch($actor, $business, 'services.edit');
    }

    public function manageMedia(User $actor, Business $business): bool
    {
        if ($business->isOwnedBy($actor)) {
            return true;
        }

        if ($business->activeMemberFor($actor)?->hasCapability('media.manage')) {
            return true;
        }

        return $this->orgPolicy()->canAccessBranch($actor, $business, 'media.manage');
    }

    public function submitVerification(User $actor, Business $business): bool
    {
        if ($business->isOwnedBy($actor)) {
            return true;
        }

        if ($business->activeMemberFor($actor)?->hasCapability('verification.submit')) {
            return true;
        }

        return $this->orgPolicy()->canAccessBranch($actor, $business, 'kyc');
    }

    public function viewLeads(User $actor, Business $business): bool
    {
        if ($business->isOwnedBy($actor)) {
            return true;
        }

        if ($business->activeMemberFor($actor)?->hasCapability('leads.manage')) {
            return true;
        }

        return $this->orgPolicy()->canAccessBranch($actor, $business, 'leads');
    }

    public function manageLeads(User $actor, Business $business): bool
    {
        if ($business->isOwnedBy($actor)) {
            return true;
        }

        if ($business->activeMemberFor($actor)?->hasCapability('leads.manage')) {
            return true;
        }

        return $this->orgPolicy()->canAccessBranch($actor, $business, 'leads');
    }

    public function viewReviews(User $actor, Business $business): bool
    {
        if ($business->isOwnedBy($actor)) {
            return true;
        }

        if ($business->activeMemberFor($actor)?->hasCapability('reviews.reply')) {
            return true;
        }

        return $this->orgPolicy()->canAccessBranch($actor, $business, 'reviews');
    }

    public function manageReviews(User $actor, Business $business): bool
    {
        if ($business->isOwnedBy($actor)) {
            return true;
        }

        if ($business->activeMemberFor($actor)?->hasCapability('reviews.reply')) {
            return true;
        }

        return $this->orgPolicy()->canAccessBranch($actor, $business, 'reviews');
    }

    public function manageStaff(User $actor, Business $business): bool
    {
        if ($business->isOwnedBy($actor)) {
            return true;
        }

        return $this->orgPolicy()->canAccessBranch($actor, $business, 'staff');
    }

    private function orgPolicy(): OrganizationAccessPolicy
    {
        return app(OrganizationAccessPolicy::class);
    }

    public function claim(User $actor, Business $business): bool
    {
        return $actor->status === 'active'
            && ! $business->is_claimed
            && $business->owner_user_id === null
            && $business->status === BusinessStatus::Active;
    }

    public function delete(User $actor, Business $business): bool
    {
        return $business->isOwnedBy($actor);
    }
}
