<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Identity\Models\Permission;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Permission>
 */
class PermissionFactory extends Factory
{
    protected $model = Permission::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $group = fake()->randomElement(['business', 'leads', 'reviews', 'kyc', 'taxonomy']);
        $action = Str::slug(fake()->unique()->words(2, true), '_');

        return [
            'name' => "{$group}.{$action}",
            'label' => Str::headline($action),
            'group_name' => $group,
        ];
    }
}
