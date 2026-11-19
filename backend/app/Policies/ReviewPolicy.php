<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domains\Reviews\Models\Review;
use App\Models\User;

final class ReviewPolicy
{
    public function view(User $actor, Review $review): bool
    {
        if ($review->user_id === $actor->id) {
            return true;
        }

        return $this->viewAdmin($actor);
    }

    public function viewAdmin(User $actor): bool
    {
        return $actor->hasRole('super_admin') || $actor->hasRole('support');
    }

    public function moderateAdmin(User $actor, Review $review): bool
    {
        return $actor->hasRole('super_admin');
    }
}
