<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domains\Directory\Models\Business;
use App\Domains\Media\Models\BusinessMedia;
use App\Models\User;

final class BusinessMediaPolicy
{
    public function delete(User $actor, BusinessMedia $media, Business $business): bool
    {
        if ((int) $media->business_id !== (int) $business->id) {
            return false;
        }

        return (new BusinessPolicy)->manageMedia($actor, $business);
    }
}
