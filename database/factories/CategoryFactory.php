<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->unique()->words(2, true),
            'type' => 'both',
        ];
    }
}
