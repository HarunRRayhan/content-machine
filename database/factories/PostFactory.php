<?php

namespace Database\Factories;

use App\Models\Post;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $number = fake()->unique()->numberBetween(1, 1000000);

        return [
            'workspace_id' => Workspace::factory(),
            'number' => $number,
            'human_id' => "P-{$number}",
            'title' => fake()->sentence(),
            'status' => 'draft',
        ];
    }
}
