<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Taxonomy\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'parent_id' => null,
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'schema_org_type' => 'LocalBusiness',
            'icon_name' => 'building-storefront',
            'depth' => 0,
            'is_featured' => false,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    public function childOf(Category $parent): static
    {
        return $this->state(fn (): array => [
            'parent_id' => $parent->id,
            'depth' => $parent->depth + 1,
        ]);
    }
}
