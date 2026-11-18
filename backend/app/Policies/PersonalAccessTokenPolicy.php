<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domains\Identity\Models\PersonalAccessToken;
use App\Models\User;

final class PersonalAccessTokenPolicy
{
    public function view(User $actor, PersonalAccessToken $token): bool
    {
        return $token->tokenable_type === User::class
            && (int) $token->tokenable_id === (int) $actor->id;
    }

    public function delete(User $actor, PersonalAccessToken $token): bool
    {
        return $token->tokenable_type === User::class
            && (int) $token->tokenable_id === (int) $actor->id;
    }
}
