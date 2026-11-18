<?php

namespace App\Console\Commands;

use App\Actions\Postsyncer\PublishVideoAction;
use App\Models\Video;
use Illuminate\Console\Command;
use Throwable;

class RecoverVideoPublishPlanDriftCommand extends Command
{
    protected $signature = 'postsyncer:recover-video-plan-drift
                            {workspace_id : Content Machine workspace id}
                            {video : Content Machine video human id}
                            {--confirm-failed : Explicitly accept a matching remote post whose status is FAILED}';

    protected $aliases = [
        'postsyncer:recover-video-publish',
        'postsyncer:recover-plan-drift-video',
    ];

    protected $description = 'Recover a fully checkpointed video after explicit plan-drift approval';

    public function handle(PublishVideoAction $action): int
    {
        $workspaceId = (int) $this->argument('workspace_id');
        $humanId = (string) $this->argument('video');
        $video = Video::query()
            ->where('workspace_id', $workspaceId)
            ->where('human_id', $humanId)
            ->first();

        if ($video === null) {
            $this->components->error("Video {$humanId} was not found in workspace {$workspaceId}.");

            return self::FAILURE;
        }

        try {
            $action->recoverPlanDrift($video, (bool) $this->option('confirm-failed'));
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(
            "PostSyncer video groups for {$humanId} were recovered from the stored plan."
        );

        return self::SUCCESS;
    }
}
