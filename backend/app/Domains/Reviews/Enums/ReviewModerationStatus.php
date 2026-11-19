<?php

declare(strict_types=1);

namespace App\Domains\Reviews\Enums;

enum ReviewModerationStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Flagged = 'flagged';
    case Hidden = 'hidden';
    case Rejected = 'rejected';
}
