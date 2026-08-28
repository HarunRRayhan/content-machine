<?php

namespace App\Console\Commands;

use App\Actions\Media\SyncPresentationLibraryAction;
use App\Models\Workspace;
use Illuminate\Console\Command;

class SyncPresentationLibraryCommand extends Command
{
    protected $signature = 'cm:sync-presentation-library {workspace? : Workspace id; omit to sync all workspaces}';

    protected $description = 'Sync presentation-library SVG assets into workspace media';

    public function handle(SyncPresentationLibraryAction $syncPresentationLibraryAction): int
    {
        $workspaceId = $this->argument('workspace');

        $workspaces = $workspaceId !== null
            ? Workspace::query()->whereKey($workspaceId)->get()
            : Workspace::query()->orderBy('id')->get();

        if ($workspaces->isEmpty()) {
            $this->error('No workspace found.');

            return self::FAILURE;
        }

        $total = 0;

        foreach ($workspaces as $workspace) {
            $synced = $syncPresentationLibraryAction->handle($workspace);
            $total += $synced;
            $this->line("Workspace {$workspace->id}: synced {$synced} presentation asset(s).");
        }

        $this->info("Done. {$total} asset(s) created or updated.");

        return self::SUCCESS;
    }
}
