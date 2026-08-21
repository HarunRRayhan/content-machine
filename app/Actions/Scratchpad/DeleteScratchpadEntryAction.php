<?php

namespace App\Actions\Scratchpad;

use App\Models\Attachment;
use App\Models\MediaAsset;
use App\Models\ScratchpadEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Hard-deletes a scratchpad entry: its status/content history, its
 * transcriptions, its attachments, and (unless another attachment still
 * points at the same deduped file) the underlying media asset and stored
 * file. A triaged entry already has a real Idea pointing back at it
 * (`ideas.scratchpad_entry_id`), so deleting it would silently sever that
 * link; this refuses rather than doing that quietly.
 *
 * @throws RuntimeException if the entry has already been triaged into an idea
 */
class DeleteScratchpadEntryAction
{
    public function handle(ScratchpadEntry $entry): void
    {
        if ($entry->status === 'triaged') {
            throw new RuntimeException("This entry has already been triaged into an idea and can't be deleted.");
        }

        DB::transaction(function () use ($entry) {
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
