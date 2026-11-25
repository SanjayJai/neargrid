<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domains\Domains\Models\Domain;
use App\Models\User;

final class DomainPolicy
{
    public function viewAdmin(User $actor): bool
    {
        return $this->isActiveSuperAdmin($actor);
    }

    public function create(User $actor): bool
    {
        return $this->isActiveSuperAdmin($actor);
    }

    public function update(User $actor, Domain $domain): bool
    {
        return $this->isActiveSuperAdmin($actor);
    }

    public function verify(User $actor, Domain $domain): bool
    {
        return $this->isActiveSuperAdmin($actor);
    }

    public function manageSsl(User $actor, Domain $domain): bool
    {
        return $this->isActiveSuperAdmin($actor);
    }

    public function promotePrimary(User $actor, Domain $domain): bool
    {
        return $this->isActiveSuperAdmin($actor);
    }

    public function delete(User $actor, Domain $domain): bool
    {
        return $this->isActiveSuperAdmin($actor);
    }

    private function isActiveSuperAdmin(User $actor): bool
    {
        return $actor->status === 'active' && $actor->hasRole('super_admin');
    }
}
