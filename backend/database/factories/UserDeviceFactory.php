<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Identity\Models\UserDevice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UserDevice>
 */
class UserDeviceFactory extends Factory
{
    protected $model = UserDevice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'platform' => fake()->randomElement(['web', 'android', 'ios']),
            'device_identifier' => 'dev-'.Str::uuid(),
            'fcm_token' => 'fcm_'.Str::random(40),
            'app_version' => '1.0.0',
            'last_active_at' => now(),
        ];
    }
}
