<?php

namespace App\Actions\Scratchpad;

use App\Models\ScratchpadEntry;
use App\Models\Workspace;

/**
 * Bulk-delete entry point for a "delete/clear my notes" request that names
 * no specific entry: deletes the same set /notes would currently list (the
 * workspace's most recent untriaged captures), via DeleteScratchpadEntryAction
 * for each so the same history/attachment/media cleanup guarantees apply.
 */
class DeleteRecentScratchpadEntriesAction
{
    private const LIMIT = 10;

    public function __construct(
        private readonly DeleteScratchpadEntryAction $deleteScratchpadEntryAction,
    ) {}

    public function handle(Workspace $workspace): int
    {
        $entries = ScratchpadEntry::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', 'new')
            ->orderByDesc('captured_at')
            ->limit(self::LIMIT)
            ->get();

        foreach ($entries as $entry) {
            $this->deleteScratchpadEntryAction->handle($entry);
        }

        return $entries->count();
    }
}
