<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Locality>
 */
class LocalityFactory extends Factory
{
    protected $model = Locality::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->streetName();

        return [
            'city_id' => City::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'pincode' => fake()->numerify('600###'),
            'latitude' => fake()->latitude(12.8, 13.2),
            'longitude' => fake()->longitude(80.1, 80.3),
            'is_popular' => false,
            'is_active' => true,
        ];
    }
}
