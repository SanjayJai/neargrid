<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Location\Models\City;
use App\Domains\Location\Models\State;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<City>
 */
class CityFactory extends Factory
{
    protected $model = City::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->city();

        return [
            'state_id' => State::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'latitude' => fake()->latitude(8.0, 28.0),
            'longitude' => fake()->longitude(72.0, 88.0),
            'timezone' => 'Asia/Kolkata',
            'is_popular' => false,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
