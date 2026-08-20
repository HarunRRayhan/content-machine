<?php

namespace Database\Factories;

use App\Models\Video;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Video>
 */
class VideoFactory extends Factory
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
            'human_id' => "V-{$number}",
            'title' => fake()->sentence(),
            'status' => 'draft',
        ];
    }
}
