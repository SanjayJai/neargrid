<?php

declare(strict_types=1);

namespace App\Domains\Identity\Enums;

enum OtpPurpose: string
{
    case Login = 'login';
    case BusinessRegister = 'business_register';
    case EnquiryVerify = 'enquiry_verify';
    case ReviewVerify = 'review_verify';
    case PhoneChange = 'phone_change';

    /**
     * Purposes permitted through public /api/v1/auth/otp/* endpoints.
     * Admin authentication is strictly excluded from OTP flows.
     *
     * @return list<self>
     */
    public static function authAllowed(): array
    {
        return [
            self::Login,
            self::BusinessRegister,
        ];
    }

    /**
     * @return list<string>
     */
    public static function authAllowedValues(): array
    {
        return array_map(
            static fn (self $purpose): string => $purpose->value,
            self::authAllowed()
        );
    }
}
