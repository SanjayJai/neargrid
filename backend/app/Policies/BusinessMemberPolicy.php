<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domains\Directory\Enums\BusinessMemberRole;
use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Models\BusinessMember;
use App\Models\User;

final class BusinessMemberPolicy
{
    public function update(User $actor, BusinessMember $member, Business $business): bool
    {
        if ((int) $member->business_id !== (int) $business->id) {
            return false;
        }

        if ($member->role === BusinessMemberRole::Owner) {
            return false;
        }

        return $business->isOwnedBy($actor);
    }

    public function delete(User $actor, BusinessMember $member, Business $business): bool
    {
        if ((int) $member->business_id !== (int) $business->id) {
            return false;
        }

        if ($member->role === BusinessMemberRole::Owner) {
            return false;
        }

        return $business->isOwnedBy($actor);
    }
}
