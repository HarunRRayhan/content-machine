<?php

namespace App\Actions\Postsyncer;

use App\Jobs\PublishVideoJob;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;
use Illuminate\Validation\ValidationException;

class EnqueueVideoPublishAction
{
    /**
     * Queue a PostSyncer publish for a video. The worker runs PublishVideoJob.
     *
     * @param  array{when?: string|null, platforms?: list<string>, confirm_ask?: bool}  $options
     */
    public function handle(Video $video, Workspace $workspace, array $options = []): Video
    {
        abort_if($video->workspace_id !== $workspace->id, 404);

        $config = PostsyncerConfig::fromWorkspace($workspace);

        if (! $config->isReadyForPublish()) {
            throw ValidationException::withMessages([
                'publish' => __('PostSyncer is not configured for publishing.'),
            ]);
        }

        if (in_array($video->publish_state, ['queued', 'running'], true)) {
            throw ValidationException::withMessages([
                'publish' => __('A publish is already in progress.'),
            ]);
        }

        $filtered = array_filter($options, fn ($value) => $value !== null);

        $video->forceFill([
            'publish_state' => 'queued',
            'publish_error' => null,
        ])->save();

        PublishVideoJob::dispatch($video, $filtered);

        return $video->fresh() ?? $video;
    }
}
