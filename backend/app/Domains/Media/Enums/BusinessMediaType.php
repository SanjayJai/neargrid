<?php

declare(strict_types=1);

namespace App\Domains\Media\Enums;

enum BusinessMediaType: string
{
    case Logo = 'logo';
    case Cover = 'cover';
    case Gallery = 'gallery';
    case Interior = 'interior';
    case Exterior = 'exterior';
    case Product = 'product';
    case Menu = 'menu';
    case Certificate = 'certificate';

    public function isSingleton(): bool
    {
        return $this === self::Logo || $this === self::Cover;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
