<?php

namespace App\Actions\Scratchpad;

use App\Models\ScratchpadEntry;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

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
        return DB::transaction(function () use ($workspace): int {
            // Select and delete the whole batch in one transaction. A worker
            // crash rolls the batch back, so a replay cannot delete the next
            // batch after partially deleting this one.
            $entries = ScratchpadEntry::query()
                ->where('workspace_id', $workspace->id)
                ->where('status', 'new')
                ->orderByDesc('captured_at')
                ->limit(self::LIMIT)
                ->lockForUpdate()
                ->get();

            foreach ($entries as $entry) {
                $this->deleteScratchpadEntryAction->handle($entry);
            }

            return $entries->count();
        });
    }
}
