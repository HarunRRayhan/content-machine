<?php

namespace App\Actions\Scratchpad;

use App\Data\Scratchpad\UpdateScratchpadEntryData;
use App\Models\ScratchpadEntry;
use RuntimeException;

/**
 * Edits a scratchpad entry's content fields (title/body/language). Status
 * is deliberately not editable here — routing happens through triage, and
 * a dropped entry is finished, not reworkable.
 *
 * Every actually-changed field is recorded via recordFieldChange before
 * it's written, so content_versions keeps the same append-only edit trail
 * the dashboard's captures already have.
 *
 * @throws RuntimeException if the entry has been dropped
 */
class UpdateScratchpadEntryAction
{
    public function handle(ScratchpadEntry $entry, UpdateScratchpadEntryData $data): ScratchpadEntry
    {
        if ($entry->status === 'dropped') {
            throw new RuntimeException("A dropped entry can't be edited.");
        }

        $changes = [];

        foreach ($data->changes() as $field => $new) {
            $old = $entry->getAttribute($field);

            if ($old !== $new) {
                $entry->recordFieldChange($field, $old, $new);
                $changes[$field] = $new;
            }
        }

        if ($changes !== []) {
            $entry->forceFill($changes)->save();
        }

        return $entry;
    }
}
