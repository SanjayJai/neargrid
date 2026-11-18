<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone_e164' => '+919'.fake()->unique()->numerify('#########'),
            'phone_verified_at' => now(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'status' => 'active',
            'remember_token' => Str::random(10),
        ];
    }

    public function otpOnly(): static
    {
        return $this->state(fn (): array => [
            'email' => null,
            'email_verified_at' => null,
            'password' => null,
        ]);
    }

    public function unverified(): static
    {
        return $this->state(fn (): array => [
            'email_verified_at' => null,
            'phone_verified_at' => null,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => [
            'status' => 'suspended',
        ]);
    }
}
