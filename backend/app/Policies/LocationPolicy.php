<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Location\Models\State;
use App\Models\User;

final class LocationPolicy
{
    public function viewAdmin(User $actor): bool
    {
        return $actor->status === 'active' && $actor->hasRole('super_admin');
    }

    public function create(User $actor): bool
    {
        return $actor->status === 'active' && $actor->hasRole('super_admin');
    }

    public function update(User $actor, State|City|Locality $location): bool
    {
        return $actor->status === 'active' && $actor->hasRole('super_admin');
    }

    public function delete(User $actor, State|City|Locality $location): bool
    {
        return $actor->status === 'active' && $actor->hasRole('super_admin');
    }
}
