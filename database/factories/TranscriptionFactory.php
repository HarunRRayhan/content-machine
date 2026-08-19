<?php

namespace Database\Factories;

use App\Models\MediaAsset;
use App\Models\Transcription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transcription>
 */
class TranscriptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'media_asset_id' => MediaAsset::factory(),
            'status' => 'pending',
        ];
    }

    public function done(): static
    {
        return $this->state(fn () => [
            'status' => 'done',
            'text' => fake()->paragraph(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => 'failed',
            'error_code' => 'transcription_failed',
            'error_message' => fake()->sentence(),
        ]);
    }
}
