<?php

namespace Database\Factories;

use App\Models\MediaAsset;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaAsset>
 */
class MediaAssetFactory extends Factory
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
            'kind' => 'image',
            'disk' => 'local',
            'path' => 'media/'.fake()->uuid().'.jpg',
            'mime' => 'image/jpeg',
            'bytes' => fake()->numberBetween(1000, 5_000_000),
        ];
    }
}
