<?php

namespace App\Actions\Scratchpad;

use App\Actions\Scratchpad\Concerns\ResolvesMediaAsset;
use App\Data\Scratchpad\CaptureScratchpadVoiceData;
use App\Models\ScratchpadEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Captures a voice memo into the Scratch Pad: stores the uploaded audio on
 * the `scratchpad` disk (deduping by sha256, see ResolvesMediaAsset),
 * attaches it to a new ScratchpadEntry, and records the null -> new status
 * transition, same shape as CaptureTextNoteAction. No transcription runs
 * here, that's a separate later phase; the entry simply has no transcript
 * yet.
 *
 * $capturedBy is nullable for the same reason as CaptureTextNoteAction's:
 * a Telegram-originated capture has no Laravel User to pass.
 */
class CaptureScratchpadVoiceAction
{
    use ResolvesMediaAsset;

    public function handle(Workspace $workspace, ?User $capturedBy, CaptureScratchpadVoiceData $data): ScratchpadEntry
    {
        $mediaAsset = $this->resolveMediaAsset($workspace, $capturedBy, $data->file, 'audio');

        return DB::transaction(function () use ($workspace, $mediaAsset, $data) {
            $entry = ScratchpadEntry::create([
                'workspace_id' => $workspace->id,
                'kind' => 'voice',
                'source' => $data->source,
                'captured_at' => now(),
                'language' => $data->language,
                'status' => 'new',
            ]);

            $entry->attachments()->create([
                'media_asset_id' => $mediaAsset->id,
                'role' => 'audio',
                'position' => 0,
            ]);

            $entry->recordStatusTransition(null, 'new');

            return $entry;
        });
    }
}
