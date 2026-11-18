<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Models\BusinessService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessService>
 */
class BusinessServiceFactory extends Factory
{
    protected $model = BusinessService::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'price_min_minor' => 50000,
            'price_max_minor' => 150000,
            'currency' => 'INR',
            'price_unit' => 'per visit',
            'sort_order' => 1,
            'is_active' => true,
        ];
    }
}
