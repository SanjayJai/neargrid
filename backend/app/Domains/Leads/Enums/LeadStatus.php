<?php

declare(strict_types=1);

namespace App\Domains\Leads\Enums;

enum LeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Converted = 'converted';
    case Archived = 'archived';
}
