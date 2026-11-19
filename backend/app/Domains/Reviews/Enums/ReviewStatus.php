<?php

declare(strict_types=1);

namespace App\Domains\Reviews\Enums;

enum ReviewStatus: string
{
    case PendingVerification = 'pending_verification';
    case Published = 'published';
    case Flagged = 'flagged';
    case Hidden = 'hidden';
    case Rejected = 'rejected';
}
