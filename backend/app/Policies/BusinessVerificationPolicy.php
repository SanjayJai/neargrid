<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final class BusinessVerificationPolicy
{
    public function viewAdmin(User $actor): bool
    {
        return $actor->hasPermission('kyc.review') || $actor->hasRole('super_admin');
    }

    public function reviewAdmin(User $actor): bool
    {
        return $actor->hasPermission('kyc.review') || $actor->hasRole('super_admin');
    }
}
