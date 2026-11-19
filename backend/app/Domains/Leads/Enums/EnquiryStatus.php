<?php

declare(strict_types=1);

namespace App\Domains\Leads\Enums;

enum EnquiryStatus: string
{
    case PendingOtp = 'pending_otp';
    case Active = 'active';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Cancelled, self::Expired], true);
    }
}
