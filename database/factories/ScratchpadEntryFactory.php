<?php

namespace Database\Factories;

use App\Models\ScratchpadEntry;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScratchpadEntry>
 */
class ScratchpadEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'kind' => 'text',
            'captured_at' => now(),
            'source' => 'web',
            'body' => fake()->sentence(),
            'status' => 'new',
        ];
    }

    public function triaged(): static
    {
        return $this->state(fn () => [
            'status' => 'triaged',
            'triaged_at' => now(),
        ]);
    }

    public function dropped(): static
    {
        return $this->state(fn () => [
            'status' => 'dropped',
            'drop_reason' => fake()->sentence(),
        ]);
    }
}
