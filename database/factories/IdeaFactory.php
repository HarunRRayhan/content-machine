<?php

namespace Database\Factories;

use App\Models\Idea;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Idea>
 */
class IdeaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->sentence();
        $number = fake()->unique()->numberBetween(1, 1000000);

        return [
            'workspace_id' => Workspace::factory(),
            'kind' => 'post',
            'number' => $number,
            'human_id' => "PI-{$number}",
            'title' => $title,
            'slug' => Str::slug($title),
            'status' => 'open',
        ];
    }

    public function promoted(): static
    {
        return $this->state(fn () => ['status' => 'promoted']);
    }

    public function dropped(): static
    {
        return $this->state(fn () => [
            'status' => 'dropped',
            'drop_reason' => fake()->sentence(),
        ]);
    }
}
