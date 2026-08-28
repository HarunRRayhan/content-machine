<?php

namespace App\Actions\Media;

use App\Models\MediaAsset;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DeleteLibraryMediaAction
{
    public function handle(MediaAsset $asset): void
    {
        if ($asset->attachments()->exists()) {
            throw new RuntimeException('This file is still attached elsewhere and cannot be deleted.');
        }

        Storage::disk($asset->disk)->delete($asset->path);
        $asset->delete();
    }
}
