<?php

namespace Database\Factories;

use App\Models\ContentId;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentId>
 */
class ContentIdFactory extends Factory
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
            'kind' => 'post_idea',
            'number' => $number,
            'human_id' => "PI-{$number}",
            'reserved_via' => 'web',
            'reserved_at' => now(),
        ];
    }
}
