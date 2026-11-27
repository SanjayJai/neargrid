<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domains\Advertising\Models\AdCampaign;
use App\Domains\Directory\Models\OrganizationMember;
use App\Models\User;

final class AdCampaignPolicy
{
    public function view(User $actor, AdCampaign $campaign): bool
    {
        if ($actor->status !== 'active') {
            return false;
        }

        if ($actor->hasRole('super_admin')) {
            return true;
        }

        if ($campaign->organization_id === null) {
            return (int) $campaign->created_by_user_id === (int) $actor->id;
        }

        if ((int) $campaign->organization?->owner_user_id === (int) $actor->id) {
            return true;
        }

        return OrganizationMember::query()
            ->where('organization_id', $campaign->organization_id)
            ->where('user_id', $actor->id)
            ->where('status', 'active')
            ->exists();
    }

    public function manage(User $actor, AdCampaign $campaign): bool
    {
        return $this->view($actor, $campaign);
    }
}
