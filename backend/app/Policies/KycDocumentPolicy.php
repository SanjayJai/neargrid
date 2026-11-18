<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domains\Directory\Models\Business;
use App\Domains\Media\Models\KycDocument;
use App\Models\User;

final class KycDocumentPolicy
{
    public function view(User $actor, KycDocument $document, Business $business): bool
    {
        if ((int) $document->business_id !== (int) $business->id) {
            return false;
        }

        if ($actor->hasPermission('kyc.review')) {
            return true;
        }

        return (new BusinessPolicy)->submitVerification($actor, $business);
    }

    public function download(User $actor, KycDocument $document): bool
    {
        if ($actor->hasPermission('kyc.review')) {
            return true;
        }

        $business = $document->business;
        if ($business === null) {
            return false;
        }

        return (new BusinessPolicy)->submitVerification($actor, $business);
    }

    public function delete(User $actor, KycDocument $document, Business $business): bool
    {
        if ((int) $document->business_id !== (int) $business->id) {
            return false;
        }

        return (new BusinessPolicy)->submitVerification($actor, $business);
    }
}
