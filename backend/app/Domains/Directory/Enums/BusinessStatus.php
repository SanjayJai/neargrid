<?php

declare(strict_types=1);

namespace App\Domains\Directory\Enums;

enum BusinessStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Active = 'active';
    case Suspended = 'suspended';
    case Rejected = 'rejected';

    public function isPubliclyVisible(): bool
    {
        return $this === self::Active;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
