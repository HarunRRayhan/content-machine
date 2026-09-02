<?php

namespace App\Console\Commands;

use App\Actions\Postsyncer\PublishPostAction;
use App\Models\Post;
use Illuminate\Console\Command;
use Throwable;

class RecoverPostPublishPlanDriftCommand extends Command
{
    protected $signature = 'postsyncer:recover-post-plan-drift
                            {workspace_id : Content Machine workspace id}
                            {post : Content Machine post human id}
                            {--confirm-failed : Explicitly accept a matching remote post whose status is FAILED}';

    protected $aliases = [
        'postsyncer:recover-post-publish',
        'postsyncer:recover-plan-drift-post',
    ];

    protected $description = 'Recover a fully checkpointed post after explicit plan-drift approval';

    public function handle(PublishPostAction $action): int
    {
        $workspaceId = (int) $this->argument('workspace_id');
        $humanId = (string) $this->argument('post');
        $post = Post::query()
            ->where('workspace_id', $workspaceId)
            ->where('human_id', $humanId)
            ->first();

        if ($post === null) {
            $this->components->error("Post {$humanId} was not found in workspace {$workspaceId}.");

            return self::FAILURE;
        }

        try {
            $action->recoverPlanDrift($post, (bool) $this->option('confirm-failed'));
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(
            "PostSyncer post groups for {$humanId} were recovered from the stored plan."
        );

        return self::SUCCESS;
    }
}
