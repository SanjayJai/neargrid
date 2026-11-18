<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Identity\Models\OtpChallenge;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OtpChallenge>
 */
class OtpChallengeFactory extends Factory
{
    protected $model = OtpChallenge::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $otp = (string) random_int(100000, 999999);
        $pepper = (string) config('directory.otp.pepper', 'test-pepper');

        return [
            'phone_e164' => '+919'.fake()->numerify('#########'),
            'purpose' => 'login',
            'code_hash' => hash_hmac('sha256', $otp, $pepper),
            'attempts' => 0,
            'max_attempts' => 5,
            'request_ip_hash' => hash('sha256', fake()->ipv4()),
            'expires_at' => now()->addMinutes(5),
            'verified_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function verified(): static
    {
        return $this->state(fn (): array => [
            'verified_at' => now(),
        ]);
    }
}
