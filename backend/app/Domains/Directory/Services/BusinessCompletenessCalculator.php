<?php

declare(strict_types=1);

namespace App\Domains\Directory\Services;

use App\Domains\Directory\Models\Business;

final class BusinessCompletenessCalculator
{
    public function calculate(Business $business): int
    {
        $score = 20; // Baseline for valid name, category, city, locality, address, phone

        if (! empty($business->tagline)) {
            $score += 10;
        }
        if (! empty($business->description) && strlen((string) $business->description) >= 40) {
            $score += 15;
        }
        if ($business->year_established !== null) {
            $score += 5;
        }
        if (! empty($business->whatsapp_e164) || ! empty($business->website_url) || ! empty($business->email)) {
            $score += 10;
        }
        if ($business->hours()->exists()) {
            $score += 15;
        }
        if ($business->services()->where('is_active', true)->exists()) {
            $score += 10;
        }
        if ($business->amenities()->exists() || $business->paymentMethods()->exists()) {
            $score += 10;
        }
        if ($business->photo_count >= 1) {
            $score += 5;
        }

        return min(100, $score);
    }
}
