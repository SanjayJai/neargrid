<?php

declare(strict_types=1);

namespace App\Domains\Reviews\Enums;

enum ReviewTrustBadge: string
{
    case VerifiedCustomer = 'verified_customer';
    case VerifiedLead = 'verified_lead';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::VerifiedCustomer => 'Verified Customer',
            self::VerifiedLead => 'Verified Lead',
            self::None => '',
        };
    }
}
