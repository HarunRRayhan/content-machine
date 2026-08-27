<?php

namespace App\Actions\Postsyncer;

use App\Jobs\PublishPostJob;
use App\Models\Post;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;
use Illuminate\Validation\ValidationException;

class EnqueuePostPublishAction
{
    /**
     * Queue a PostSyncer publish for a post. The worker runs PublishPostJob.
     *
     * @param  array{when?: string|null, platforms?: list<string>, confirm_ask?: bool}  $options
     */
    public function handle(Post $post, Workspace $workspace, array $options = []): Post
    {
        abort_if($post->workspace_id !== $workspace->id, 404);

        $config = PostsyncerConfig::fromWorkspace($workspace);

        if (! $config->isReadyForPublish()) {
            throw ValidationException::withMessages([
                'publish' => __('PostSyncer is not configured for publishing.'),
            ]);
        }

        if (in_array($post->publish_state, ['queued', 'running'], true)) {
            throw ValidationException::withMessages([
                'publish' => __('A publish is already in progress.'),
            ]);
        }

        $filtered = array_filter($options, fn ($value) => $value !== null);

        $post->forceFill([
            'publish_state' => 'queued',
            'publish_error' => null,
        ])->save();

        PublishPostJob::dispatch($post, $filtered);

        return $post->fresh() ?? $post;
    }
}
