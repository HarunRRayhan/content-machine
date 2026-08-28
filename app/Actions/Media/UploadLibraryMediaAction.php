<?php

namespace App\Actions\Media;

use App\Actions\Scratchpad\Concerns\ResolvesMediaAsset;
use App\Data\Media\UploadLibraryMediaData;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Media\MediaLibraryTab;

class UploadLibraryMediaAction
{
    use ResolvesMediaAsset;

    public function handle(Workspace $workspace, ?User $uploadedBy, UploadLibraryMediaData $data): MediaAsset
    {
        $kind = match ($data->tab) {
            MediaLibraryTab::Images, MediaLibraryTab::Gifs => 'image',
            MediaLibraryTab::Videos => 'video',
        };

        $asset = $this->resolveMediaAsset($workspace, $uploadedBy, $data->file, $kind);

        $meta = $asset->meta;
        $meta['source'] = 'library';

        $asset->update([
            'title' => $data->title ?: ($asset->title ?? $data->file->getClientOriginalName()),
            'description' => $data->description,
            'meta' => $meta,
        ]);

        return $asset->fresh() ?? $asset;
    }
}
