<?php

namespace App\Console\Commands;

use App\Actions\Postsyncer\PublishPostAction;
use App\Models\Post;
use Illuminate\Console\Command;
use Throwable;

class ReconcilePostPublishCommand extends Command
{
    protected $signature = 'postsyncer:reconcile-post
                            {workspace_id : Content Machine workspace id}
                            {post : Content Machine post human id}
                            {postsyncer_id : PostSyncer post id created by the uncertain attempt}';

    protected $description = 'Verify and checkpoint a PostSyncer post after a lost create response';

    public function handle(PublishPostAction $action): int
    {
        $workspaceId = (int) $this->argument('workspace_id');
        $humanId = (string) $this->argument('post');
        $postsyncerId = (string) $this->argument('postsyncer_id');
        $post = Post::query()
            ->where('workspace_id', $workspaceId)
            ->where('human_id', $humanId)
            ->first();

        if ($post === null) {
            $this->components->error("Post {$humanId} was not found in workspace {$workspaceId}.");

            return self::FAILURE;
        }

        try {
            $action->reconcile($post, $postsyncerId);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(
            "PostSyncer post {$postsyncerId} was verified for {$humanId}. Retry the publish to continue.",
        );

        return self::SUCCESS;
    }
}
