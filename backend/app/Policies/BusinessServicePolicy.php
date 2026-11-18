<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Models\BusinessService;
use App\Models\User;

final class BusinessServicePolicy
{
    public function update(User $actor, BusinessService $service, Business $business): bool
    {
        if ((int) $service->business_id !== (int) $business->id) {
            return false;
        }

        return (new BusinessPolicy)->manageServices($actor, $business);
    }

    public function delete(User $actor, BusinessService $service, Business $business): bool
    {
        if ((int) $service->business_id !== (int) $business->id) {
            return false;
        }

        return (new BusinessPolicy)->manageServices($actor, $business);
    }
}
