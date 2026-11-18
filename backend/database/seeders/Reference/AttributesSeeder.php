<?php

declare(strict_types=1);

namespace Database\Seeders\Reference;

use App\Domains\Taxonomy\Models\Amenity;
use App\Domains\Taxonomy\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class AttributesSeeder extends Seeder
{
    public function run(): void
    {
        $amenities = [
            ['slug' => 'air-conditioned', 'name' => 'Air Conditioned', 'icon_name' => 'wind', 'group_name' => 'general'],
            ['slug' => 'parking-available', 'name' => 'Parking Available', 'icon_name' => 'car', 'group_name' => 'parking'],
            ['slug' => 'wheelchair-accessible', 'name' => 'Wheelchair Accessible', 'icon_name' => 'accessibility', 'group_name' => 'accessibility'],
            ['slug' => 'wifi-available', 'name' => 'Free Wi-Fi', 'icon_name' => 'wifi', 'group_name' => 'general'],
            ['slug' => 'doorstep-service', 'name' => 'Doorstep Service', 'icon_name' => 'home', 'group_name' => 'service'],
            ['slug' => 'home-delivery', 'name' => 'Home Delivery', 'icon_name' => 'truck', 'group_name' => 'service'],
            ['slug' => 'appointment-booking', 'name' => 'Appointment Recommended', 'icon_name' => 'calendar', 'group_name' => 'general'],
            ['slug' => 'emergency-support', 'name' => 'Emergency Support', 'icon_name' => 'alert-circle', 'group_name' => 'service'],
        ];

        foreach ($amenities as $amenity) {
            Amenity::query()->updateOrCreate(
                ['slug' => $amenity['slug']],
                [
                    'name' => $amenity['name'],
                    'icon_name' => $amenity['icon_name'],
                    'group_name' => $amenity['group_name'],
                    'is_active' => true,
                ]
            );
        }

        $paymentMethods = [
            ['slug' => 'upi', 'name' => 'UPI', 'icon_name' => 'smartphone'],
            ['slug' => 'cash', 'name' => 'Cash', 'icon_name' => 'banknote'],
            ['slug' => 'credit-card', 'name' => 'Credit Card', 'icon_name' => 'credit-card'],
            ['slug' => 'debit-card', 'name' => 'Debit Card', 'icon_name' => 'credit-card'],
            ['slug' => 'net-banking', 'name' => 'Net Banking', 'icon_name' => 'landmark'],
            ['slug' => 'insurance-billing', 'name' => 'Insurance Accepted', 'icon_name' => 'shield'],
        ];

        foreach ($paymentMethods as $method) {
            PaymentMethod::query()->updateOrCreate(
                ['slug' => $method['slug']],
                [
                    'name' => $method['name'],
                    'icon_name' => $method['icon_name'],
                    'is_active' => true,
                ]
            );
        }
    }
}
