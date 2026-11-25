<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domains\Seo\Models\SeoMetadata;
use App\Domains\Taxonomy\Models\Category;
use App\Domains\Taxonomy\Models\CategoryCollection;
use App\Models\User;

final class CategoryPolicy
{
    public function viewAdmin(User $actor): bool
    {
        return $actor->status === 'active' && $actor->hasRole('super_admin');
    }

    public function create(User $actor): bool
    {
        return $actor->status === 'active' && $actor->hasRole('super_admin');
    }

    public function update(User $actor, Category|CategoryCollection $entity): bool
    {
        return $actor->status === 'active' && $actor->hasRole('super_admin');
    }

    public function delete(User $actor, Category|CategoryCollection $entity): bool
    {
        return $actor->status === 'active' && $actor->hasRole('super_admin');
    }

    public function manageSeo(User $actor, ?SeoMetadata $seo = null): bool
    {
        return $actor->status === 'active' && $actor->hasRole('super_admin');
    }
}
