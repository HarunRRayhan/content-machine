<?php

namespace App\Console\Commands;

use App\Actions\Postsyncer\PublishVideoAction;
use App\Models\Video;
use Illuminate\Console\Command;
use Throwable;

class ReconcileVideoMediaPublishCommand extends Command
{
    protected $signature = 'postsyncer:reconcile-video-media
                            {workspace_id : Content Machine workspace id}
                            {video : Content Machine video human id}
                            {media_ids : Comma-separated PostSyncer media ids in upload order}';

    protected $aliases = ['postsyncer:reconcile-media-video'];

    protected $description = 'Checkpoint PostSyncer media ids after a lost video upload response';

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

        $mediaIds = $this->mediaIds();

        try {
            $action->reconcileMedia($video, $mediaIds);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(
            "PostSyncer media for {$humanId} was checkpointed. Retry the publish to create the video post.",
        );

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function mediaIds(): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', (string) $this->argument('media_ids'))),
            static fn (string $mediaId): bool => $mediaId !== '',
        ));
    }
}
