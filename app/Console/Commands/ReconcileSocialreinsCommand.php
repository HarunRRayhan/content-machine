<?php

namespace App\Console\Commands;

use App\Actions\Socialreins\ReconcileSocialreinsGroupsAction;
use Illuminate\Console\Command;

class ReconcileSocialreinsCommand extends Command
{
    protected $signature = 'socialreins:reconcile';

    protected $description = 'Check SocialReins post groups that are still publishing (fallback for a missed webhook)';

    public function handle(ReconcileSocialreinsGroupsAction $action): int
    {
        $this->info("Checked {$action->handle()} SocialReins group(s).");

        return self::SUCCESS;
    }
}
