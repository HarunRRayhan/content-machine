<?php

namespace App\Console\Commands;

use App\Actions\Postsyncer\PublishPostAction;
use App\Models\Post;
use Illuminate\Console\Command;
use Throwable;

class ReconcilePostMediaPublishCommand extends Command
{
    protected $signature = 'postsyncer:reconcile-post-media
                            {workspace_id : Content Machine workspace id}
                            {post : Content Machine post human id}
                            {media_ids : Comma-separated PostSyncer media ids in upload order}';

    protected $aliases = ['postsyncer:reconcile-media-post'];

    protected $description = 'Checkpoint PostSyncer media ids after a lost media upload response';

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

        $mediaIds = $this->mediaIds();

        try {
            $action->reconcileMedia($post, $mediaIds);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(
            "PostSyncer media for {$humanId} was checkpointed. Retry the publish to create the post.",
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
