<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Directory\Enums\BusinessStatus;
use App\Domains\Directory\Models\Business;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Taxonomy\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Business>
 */
class BusinessFactory extends Factory
{
    protected $model = Business::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'owner_user_id' => User::factory(),
            'primary_category_id' => Category::factory(),
            'city_id' => City::factory(),
            'locality_id' => function (array $attributes): int {
                return Locality::factory()->create([
                    'city_id' => $attributes['city_id'],
                ])->id;
            },
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'tagline' => fake()->sentence(6),
            'description' => fake()->paragraph(),
            'search_keywords' => fake()->words(5, true),
            'year_established' => fake()->numberBetween(2000, 2025),
            'address_line_1' => fake()->streetAddress(),
            'landmark' => 'Near Main Junction',
            'pincode' => fake()->numerify('600###'),
            'latitude' => fake()->latitude(12.9, 13.15),
            'longitude' => fake()->longitude(80.15, 80.3),
            'primary_phone_e164' => '+919'.fake()->numerify('#########'),
            'whatsapp_e164' => '+919'.fake()->numerify('#########'),
            'email' => fake()->safeEmail(),
            'status' => BusinessStatus::Active,
            'is_claimed' => true,
            'is_verified' => false,
            'is_trusted' => false,
            'completeness_score' => 65,
        ];
    }

    public function unclaimed(): static
    {
        return $this->state(fn (): array => [
            'owner_user_id' => null,
            'is_claimed' => false,
        ]);
    }

    public function pendingReview(): static
    {
        return $this->state(fn (): array => [
            'status' => BusinessStatus::PendingReview,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => [
            'status' => BusinessStatus::Suspended,
        ]);
    }

    public function verified(): static
    {
        return $this->state(fn (): array => [
            'is_verified' => true,
            'verified_at' => now(),
        ]);
    }
}
