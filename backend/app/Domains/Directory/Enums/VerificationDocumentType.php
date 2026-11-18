<?php

declare(strict_types=1);

namespace App\Domains\Directory\Enums;

enum VerificationDocumentType: string
{
    case Gstin = 'gstin';
    case Pan = 'pan';
    case TradeLicense = 'trade_license';
    case ShopAct = 'shop_act';
    case UtilityBill = 'utility_bill';
    case IncorporationCert = 'incorporation_cert';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
