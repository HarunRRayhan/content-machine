<?php

namespace App\Actions\Scratchpad;

use App\Data\Scratchpad\CaptureScratchpadLinkData;
use App\Jobs\ResolveScratchpadLinkJob;
use App\Models\ScratchpadEntry;
use App\Models\User;
use App\Models\Workspace;

/**
 * Captures a forwarded URL into the Scratch Pad immediately, then queues
 * ResolveScratchpadLinkJob to fill in a title/description afterward. The
 * entry exists and is visible the instant the URL is submitted, same as a
 * voice memo's audio is never blocked on its transcription; a slow or
 * failed resolution never costs the capture itself.
 *
 * $capturedBy is nullable for the same reason as CaptureTextNoteAction's:
 * a Telegram-originated capture has no Laravel User to pass.
 */
class CaptureScratchpadLinkAction
{
    public function handle(Workspace $workspace, ?User $capturedBy, CaptureScratchpadLinkData $data): ScratchpadEntry
    {
        $entry = ScratchpadEntry::create([
            'workspace_id' => $workspace->id,
            'kind' => 'link',
            'source' => $data->source,
            'captured_at' => now(),
            'body' => $data->url,
            'language' => $data->language,
            'status' => 'new',
            'meta' => ['url' => $data->url],
        ]);

        $entry->recordStatusTransition(null, 'new');

        ResolveScratchpadLinkJob::dispatch($entry);

        return $entry;
    }
}
