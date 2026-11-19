<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domains\Leads\Models\Enquiry;
use App\Models\User;

final class EnquiryPolicy
{
    public function view(User $actor, Enquiry $enquiry): bool
    {
        if ($enquiry->user_id === $actor->id) {
            return true;
        }

        return $this->viewAdmin($actor);
    }

    public function viewAdmin(User $actor): bool
    {
        return $actor->hasRole('super_admin') || $actor->hasRole('support');
    }

    public function manageAdmin(User $actor): bool
    {
        return $actor->hasRole('super_admin');
    }

    public function cancelAdmin(User $actor, Enquiry $enquiry): bool
    {
        return $actor->hasRole('super_admin');
    }
}
