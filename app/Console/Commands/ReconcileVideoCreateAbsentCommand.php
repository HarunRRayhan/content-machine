<?php

namespace App\Console\Commands;

use App\Actions\Postsyncer\PublishVideoAction;
use App\Models\Video;
use Illuminate\Console\Command;
use Throwable;

class ReconcileVideoCreateAbsentCommand extends Command
{
    protected $signature = 'postsyncer:reconcile-video-create-absent
                            {workspace_id : Content Machine workspace id}
                            {video : Content Machine video human id}
                            {--confirm-absent : Confirm you verified in PostSyncer that no post was created}';

    protected $description = 'Mark an uncertain PostSyncer video create as absent so the publish can be retried with the existing media';

    public function handle(PublishVideoAction $action): int
    {
        $workspaceId = (int) $this->argument('workspace_id');
        $humanId = (string) $this->argument('video');

        if (! $this->option('confirm-absent')) {
            $this->components->error(
                'Refusing to continue without --confirm-absent. Verify in PostSyncer that no post was created for this video first.'
            );

            return self::FAILURE;
        }

        $video = Video::query()
            ->where('workspace_id', $workspaceId)
            ->where('human_id', $humanId)
            ->first();

        if ($video === null) {
            $this->components->error("Video {$humanId} was not found in workspace {$workspaceId}.");

            return self::FAILURE;
        }

        try {
            $action->reconcileCreateAbsent($video);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(
            "PostSyncer create for {$humanId} was marked absent. Retry the publish to create it with the existing media."
        );

        return self::SUCCESS;
    }
}
