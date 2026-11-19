<?php

declare(strict_types=1);

namespace App\Domains\Identity\Support;

use App\Domains\Identity\Exceptions\IdentityException;
use Illuminate\Http\Response;

final class PhoneNormalizer
{
    public static function normalizeAndValidate(string $rawPhone): string
    {
        $cleaned = preg_replace('/[\s\-()]+/', '', trim($rawPhone)) ?? '';

        if (preg_match('/^\+[1-9]\d{7,14}$/', $cleaned) !== 1) {
            throw new IdentityException(
                errorCode: 'INVALID_PHONE_NUMBER',
                message: 'Please provide a valid mobile number in E.164 format (e.g., +919820012345).',
                httpStatus: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        /** @var list<string> $allowedPrefixes */
        $allowedPrefixes = config('directory.otp.allowed_country_prefixes', ['+91']);

        if ($allowedPrefixes !== []) {
            $matchesPrefix = false;
            foreach ($allowedPrefixes as $prefix) {
                if (str_starts_with($cleaned, $prefix)) {
                    $matchesPrefix = true;
                    break;
                }
            }

            if (! $matchesPrefix) {
                throw new IdentityException(
                    errorCode: 'UNSUPPORTED_COUNTRY_CODE',
                    message: 'This country code is not currently supported for OTP delivery.',
                    httpStatus: Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
        }

        // Enforce Indian 10-digit mobile number rule (starts with 6-9) for +91 numbers.
        if (str_starts_with($cleaned, '+91') && preg_match('/^\+91[6-9]\d{9}$/', $cleaned) !== 1) {
            throw new IdentityException(
                errorCode: 'INVALID_PHONE_NUMBER',
                message: 'Please provide a valid 10-digit Indian mobile number.',
                httpStatus: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $cleaned;
    }

    public static function mask(string $phoneE164): string
    {
        if (strlen($phoneE164) <= 7) {
            return substr($phoneE164, 0, 3).'***';
        }

        return substr($phoneE164, 0, 3).'******'.substr($phoneE164, -4);
    }
}
