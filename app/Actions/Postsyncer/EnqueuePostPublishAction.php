<?php

namespace App\Actions\Postsyncer;

use App\Jobs\PublishPostJob;
use App\Models\Post;
use App\Models\TelegramPostRequest;
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

        if (($post->approval_state ?? 'approved') !== 'approved') {
            throw ValidationException::withMessages([
                'publish' => __('This post needs human approval before it can be published.'),
            ]);
        }

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

        if ($this->alreadyPublishedOnPostsyncer($post)) {
            throw ValidationException::withMessages([
                'publish' => __('This post already has PostSyncer posts. Republish is not supported yet.'),
            ]);
        }

        $filtered = array_filter($options, fn ($value) => $value !== null);

        $post->forceFill([
            'publish_state' => 'queued',
            'publish_error' => null,
        ])->save();

        $post->telegramPostRequests()
            ->where('state', TelegramPostRequest::FAILED)
            ->update([
                'state' => TelegramPostRequest::APPROVED,
                'error_message' => null,
            ]);

        PublishPostJob::dispatch($post, $filtered);

        return $post->fresh() ?? $post;
    }

    private function alreadyPublishedOnPostsyncer(Post $post): bool
    {
        $groups = $post->postsyncer['groups'] ?? null;

        if (! is_array($groups)) {
            return false;
        }

        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $postId = $group['post_id'] ?? null;

            if (is_int($postId) || is_float($postId)) {
                if ($postId > 0) {
                    return true;
                }

                continue;
            }

            if (is_string($postId) && trim($postId) !== '') {
                return true;
            }
        }

        return false;
    }
}
