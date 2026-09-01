<?php

namespace App\Actions\Scratchpad;

use App\Actions\Scratchpad\Concerns\ResolvesMediaAsset;
use App\Data\Scratchpad\CaptureScratchpadPhotoData;
use App\Models\ScratchpadEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Captures a photo into the Scratch Pad: stores the uploaded image on the
 * `scratchpad` disk (deduping by sha256 against an existing MediaAsset in
 * the same workspace, see ResolvesMediaAsset), attaches it to a new
 * ScratchpadEntry, and records the null -> new status transition, same
 * shape as CaptureTextNoteAction. No OCR/AI ever reads the image here;
 * capture is pure capture.
 *
 * $capturedBy is nullable for the same reason as CaptureTextNoteAction's:
 * a Telegram-originated capture has no Laravel User to pass.
 */
class CaptureScratchpadPhotoAction
{
    use ResolvesMediaAsset;

    public function handle(
        Workspace $workspace,
        ?User $capturedBy,
        CaptureScratchpadPhotoData $data,
        ?string $telegramUpdateKey = null,
    ): ScratchpadEntry {
        $mediaAsset = $this->resolveMediaAsset($workspace, $capturedBy, $data->file, 'image');

        return DB::transaction(function () use ($workspace, $mediaAsset, $data, $telegramUpdateKey) {
            $entry = ScratchpadEntry::create([
                'workspace_id' => $workspace->id,
                'kind' => 'photo',
                'source' => $data->source,
                'telegram_update_key' => $telegramUpdateKey,
                'captured_at' => now(),
                'body' => $data->caption,
                'language' => $data->language,
                'status' => 'new',
            ]);

            $entry->attachments()->create([
                'media_asset_id' => $mediaAsset->id,
                'role' => 'image',
                'position' => 0,
            ]);

            $entry->recordStatusTransition(null, 'new');

            return $entry;
        });
    }
}
