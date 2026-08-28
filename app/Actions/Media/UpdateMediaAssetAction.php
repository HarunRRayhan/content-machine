<?php

namespace App\Actions\Media;

use App\Data\Media\UpdateMediaAssetData;
use App\Models\MediaAsset;

class UpdateMediaAssetAction
{
    public function handle(MediaAsset $asset, UpdateMediaAssetData $data): MediaAsset
    {
        $asset->update([
            'title' => $data->title,
            'description' => $data->description,
        ]);

        return $asset->fresh() ?? $asset;
    }
}
