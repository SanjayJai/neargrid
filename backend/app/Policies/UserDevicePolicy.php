<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domains\Identity\Models\UserDevice;
use App\Models\User;

final class UserDevicePolicy
{
    public function view(User $actor, UserDevice $device): bool
    {
        return (int) $device->user_id === (int) $actor->id;
    }

    public function update(User $actor, UserDevice $device): bool
    {
        return (int) $device->user_id === (int) $actor->id;
    }

    public function delete(User $actor, UserDevice $device): bool
    {
        return (int) $device->user_id === (int) $actor->id;
    }
}
