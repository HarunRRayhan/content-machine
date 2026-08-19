<?php

namespace App\Actions\Scratchpad;

use App\Data\Scratchpad\CaptureTextNoteData;
use App\Models\ScratchpadEntry;
use App\Models\User;
use App\Models\Workspace;

/**
 * Captures a plain text note into the Scratch Pad. Capture is pure capture:
 * this never routes the entry into an idea, that only happens later via
 * scratchpad-triage.
 *
 * $capturedBy isn't written to a column (scratchpad_entries has no
 * captured_by_user_id, only triaged_by_user_id), it's accepted so the
 * caller must resolve and pass a real user explicitly rather than this
 * Action reaching for global auth state itself. In the web flow the
 * controller always passes the authenticated request user, which is who
 * RecordsHistory's recordStatusTransition() below also attributes the
 * transition to (it resolves the actor from Auth::user() itself).
 */
class CaptureTextNoteAction
{
    public function handle(Workspace $workspace, User $capturedBy, CaptureTextNoteData $data): ScratchpadEntry
    {
        $entry = ScratchpadEntry::create([
            'workspace_id' => $workspace->id,
            'kind' => 'text',
            'source' => 'web',
            'captured_at' => now(),
            'body' => $data->body,
            'status' => 'new',
        ]);

        $entry->recordStatusTransition(null, 'new');

        return $entry;
    }
}
