<?php

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\MediaAsset;
use App\Models\ScratchpadEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Defaults `attachable` to a ScratchpadEntry, since that's the only
     * attachable model that exists today; a later phase adding posts/videos
     * can override with `->for($post, 'attachable')`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'attachable_type' => ScratchpadEntry::class,
            'attachable_id' => ScratchpadEntry::factory(),
            'media_asset_id' => MediaAsset::factory(),
            'role' => 'image',
            'position' => 0,
        ];
    }
}
