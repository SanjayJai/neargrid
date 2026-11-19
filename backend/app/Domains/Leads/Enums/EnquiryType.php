<?php

declare(strict_types=1);

namespace App\Domains\Leads\Enums;

enum EnquiryType: string
{
    case Direct = 'direct';
    case Broadcast = 'broadcast';
}
