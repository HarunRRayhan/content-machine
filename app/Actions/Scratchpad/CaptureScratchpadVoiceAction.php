<?php

namespace App\Actions\Scratchpad;

use App\Actions\Scratchpad\Concerns\ResolvesMediaAsset;
use App\Data\Scratchpad\CaptureScratchpadVoiceData;
use App\Jobs\TranscribeVoiceNoteJob;
use App\Models\ScratchpadEntry;
use App\Models\Transcription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Captures a voice memo into the Scratch Pad: stores the uploaded audio on
 * the `scratchpad` disk (deduping by sha256, see ResolvesMediaAsset),
 * attaches it to a new ScratchpadEntry, and records the null -> new status
 * transition, same shape as CaptureTextNoteAction. A pending Transcription
 * row is created and TranscribeVoiceNoteJob queued immediately after, same
 * "the entry exists right away, enrichment happens after" shape as
 * CaptureScratchpadLinkAction's ResolveScratchpadLinkJob — the audio is
 * never blocked on transcription, and transcription failing (e.g. no AI
 * provider configured) never costs the capture itself.
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

        [$entry, $transcription] = DB::transaction(function () use ($workspace, $mediaAsset, $data) {
            $entry = ScratchpadEntry::create([
                'workspace_id' => $workspace->id,
                'kind' => 'voice',
                'source' => $data->source,
                'captured_at' => now(),
                'language' => $data->language,
                'body' => $data->caption,
                'status' => 'new',
                'meta' => $data->telegramChatId !== null ? ['telegram_chat_id' => $data->telegramChatId] : [],
            ]);

            $entry->attachments()->create([
                'media_asset_id' => $mediaAsset->id,
                'role' => 'audio',
                'position' => 0,
            ]);

            $entry->recordStatusTransition(null, 'new');

            $transcription = Transcription::create([
                'scratchpad_entry_id' => $entry->id,
                'media_asset_id' => $mediaAsset->id,
                'status' => 'pending',
            ]);

            return [$entry, $transcription];
        });

        TranscribeVoiceNoteJob::dispatch($transcription);

        return $entry;
    }
}
