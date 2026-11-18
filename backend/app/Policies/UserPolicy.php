<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final class UserPolicy
{
    public function view(User $actor, User $target): bool
    {
        return $actor->id === $target->id;
    }

    public function update(User $actor, User $target): bool
    {
        return $actor->id === $target->id && $actor->status === 'active';
    }

    public function delete(User $actor, User $target): bool
    {
        return $actor->id === $target->id;
    }

    public function assignRole(User $actor, User $target): bool
    {
        return $actor->hasRole('super_admin');
    }
}
