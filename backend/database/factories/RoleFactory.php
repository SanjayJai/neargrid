<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Identity\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $label = fake()->unique()->jobTitle();

        return [
            'name' => Str::slug($label, '_'),
            'label' => $label,
            'description' => fake()->sentence(),
        ];
    }
}
