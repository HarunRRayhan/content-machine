<?php

namespace App\Actions\Scratchpad;

use App\Models\Attachment;
use App\Models\Idea;
use App\Models\MediaAsset;
use App\Models\ScratchpadEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Hard-deletes a scratchpad entry: its status/content history, its
 * transcriptions, its attachments, and (unless another attachment still
 * points at the same deduped file) the underlying media asset and stored
 * file. If an idea points back at this entry, it is preserved and its source
 * link is cleared before the entry is removed.
 */
class DeleteScratchpadEntryAction
{
    public function handle(ScratchpadEntry $entry): void
    {
        DB::transaction(function () use ($entry) {
            Idea::query()
                ->where('scratchpad_entry_id', $entry->id)
                ->update(['scratchpad_entry_id' => null]);

            $entry->statusTransitions()->delete();
            $entry->contentVersions()->delete();
            $entry->transcriptions()->delete();

            $mediaAssetIds = $entry->attachments()->pluck('media_asset_id')->unique();
            $entry->attachments()->delete();

            foreach ($mediaAssetIds as $mediaAssetId) {
                $this->deleteMediaAssetIfOrphaned($mediaAssetId);
            }

            $entry->delete();
        });
    }

    private function deleteMediaAssetIfOrphaned(int $mediaAssetId): void
    {
        if (Attachment::query()->where('media_asset_id', $mediaAssetId)->exists()) {
            return;
        }

        $mediaAsset = MediaAsset::find($mediaAssetId);

        if ($mediaAsset === null) {
            return;
        }

        Storage::disk($mediaAsset->disk)->delete($mediaAsset->path);
        $mediaAsset->delete();
    }
}
