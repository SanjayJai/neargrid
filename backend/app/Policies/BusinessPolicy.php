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

        return $business->activeMemberFor($actor)?->hasCapability('business.view') ?? false;
    }

    public function update(User $actor, Business $business): bool
    {
        if ($business->isOwnedBy($actor)) {
            return true;
        }

        return $business->activeMemberFor($actor)?->hasCapability('profile.edit') ?? false;
    }

    public function manageHours(User $actor, Business $business): bool
    {
        if ($business->isOwnedBy($actor)) {
            return true;
        }

        return $business->activeMemberFor($actor)?->hasCapability('hours.edit') ?? false;
    }

    public function manageServices(User $actor, Business $business): bool
    {
        if ($business->isOwnedBy($actor)) {
            return true;
        }

        return $business->activeMemberFor($actor)?->hasCapability('services.edit') ?? false;
    }

    public function manageMedia(User $actor, Business $business): bool
    {
        if ($business->isOwnedBy($actor)) {
            return true;
        }

        return $business->activeMemberFor($actor)?->hasCapability('media.manage') ?? false;
    }

    public function submitVerification(User $actor, Business $business): bool
    {
        if ($business->isOwnedBy($actor)) {
            return true;
        }

        return $business->activeMemberFor($actor)?->hasCapability('verification.submit') ?? false;
    }

    public function viewLeads(User $actor, Business $business): bool
    {
        if ($business->isOwnedBy($actor)) {
            return true;
        }

        return $business->activeMemberFor($actor)?->hasCapability('leads.manage') ?? false;
    }

    public function manageLeads(User $actor, Business $business): bool
    {
        if ($business->isOwnedBy($actor)) {
            return true;
        }

        return $business->activeMemberFor($actor)?->hasCapability('leads.manage') ?? false;
    }

    public function viewReviews(User $actor, Business $business): bool
    {
        if ($business->isOwnedBy($actor)) {
            return true;
        }

        return $business->activeMemberFor($actor)?->hasCapability('reviews.reply') ?? false;
    }

    public function manageReviews(User $actor, Business $business): bool
    {
        if ($business->isOwnedBy($actor)) {
            return true;
        }

        return $business->activeMemberFor($actor)?->hasCapability('reviews.reply') ?? false;
    }

    public function manageStaff(User $actor, Business $business): bool
    {
        return $business->isOwnedBy($actor);
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
