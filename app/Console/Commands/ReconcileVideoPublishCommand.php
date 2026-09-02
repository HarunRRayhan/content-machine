<?php

namespace App\Console\Commands;

use App\Actions\Postsyncer\PublishVideoAction;
use App\Models\Video;
use Illuminate\Console\Command;
use Throwable;

class ReconcileVideoPublishCommand extends Command
{
    protected $signature = 'postsyncer:reconcile-video
                            {workspace_id : Content Machine workspace id}
                            {video : Content Machine video human id}
                            {postsyncer_id : PostSyncer post id created by the uncertain attempt}
                            {--confirm-failed : Explicitly accept a matching remote post whose status is FAILED}';

    protected $description = 'Verify and checkpoint a PostSyncer video after a lost create response';

    public function handle(PublishVideoAction $action): int
    {
        $workspaceId = (int) $this->argument('workspace_id');
        $humanId = (string) $this->argument('video');
        $postsyncerId = (string) $this->argument('postsyncer_id');
        $video = Video::query()
            ->where('workspace_id', $workspaceId)
            ->where('human_id', $humanId)
            ->first();

        if ($video === null) {
            $this->components->error("Video {$humanId} was not found in workspace {$workspaceId}.");

            return self::FAILURE;
        }

        try {
            $action->reconcile($video, $postsyncerId, (bool) $this->option('confirm-failed'));
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
