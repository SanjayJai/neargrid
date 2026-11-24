<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Support;

final class CancellationReasonMapper
{
    /**
     * Map an internal administrator reason to a safe, customer-facing notification message.
     */
    public static function toCustomerFacingMessage(string $adminReason): string
    {
        $normalized = strtolower(trim($adminReason));

        if (str_contains($normalized, 'duplicate')) {
            return 'This enquiry was identified as a duplicate submission and closed.';
        }

        if (str_contains($normalized, 'spam') || str_contains($normalized, 'policy') || str_contains($normalized, 'ineligible') || str_contains($normalized, 'abuse')) {
            return 'This enquiry could not be fulfilled as it did not meet platform directory guidelines.';
        }

        if (str_contains($normalized, 'customer request') || str_contains($normalized, 'user request') || str_contains($normalized, 'requested by user') || str_contains($normalized, 'cancelled by customer')) {
            return 'This enquiry was cancelled per your request.';
        }

        if (str_contains($normalized, 'invalid') || str_contains($normalized, 'unreachable') || str_contains($normalized, 'wrong number') || str_contains($normalized, 'invalid phone')) {
            return 'This enquiry was closed due to incomplete or unreachable contact details.';
        }

        if (str_contains($normalized, 'test') || str_contains($normalized, 'testing')) {
            return 'This test enquiry has been removed by platform administration.';
        }

        return 'This enquiry has been closed by administration. Please contact support if you need assistance.';
    }
}
