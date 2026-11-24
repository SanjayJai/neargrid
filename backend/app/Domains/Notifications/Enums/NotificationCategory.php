<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Enums;

enum NotificationCategory: string
{
    case Enquiry = 'enquiry';
    case Review = 'review';
    case Account = 'account';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
