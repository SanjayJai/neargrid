<?php

declare(strict_types=1);

namespace App\Domains\Leads\Enums;

enum EnquiryUrgency: string
{
    case Immediate = 'immediate';
    case WithinWeek = 'within_week';
    case Flexible = 'flexible';
}
