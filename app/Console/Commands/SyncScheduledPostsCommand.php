<?php

namespace App\Console\Commands;

use App\Actions\Postsyncer\SyncScheduledPostsAction;
use Illuminate\Console\Command;

class SyncScheduledPostsCommand extends Command
{
    protected $signature = 'postsyncer:sync-scheduled';

    protected $description = 'Mark scheduled posts and videos as posted once PostSyncer says they are live';

    public function handle(SyncScheduledPostsAction $action): int
    {
        $marked = $action->handle();
        $this->info("Marked {$marked['posts']} scheduled post(s) as posted.");
        $this->info("Marked {$marked['videos']} scheduled video(s) as posted.");

        return self::SUCCESS;
    }
}
