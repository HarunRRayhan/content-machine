<?php

namespace App\Actions\Postsyncer;

use App\Models\Post;
use App\Support\Postsyncer\PostsyncerClient;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Postsyncer\PostsyncerException;

/**
 * Live-check scheduled posts against PostSyncer and mark them posted
 * once every stored group is actually PUBLISHED.
 */
class SyncScheduledPostsAction
{
    public function handle(): int
    {
        $marked = 0;

        $posts = Post::query()
            ->where('status', 'scheduled')
            ->with('workspace')
            ->get();

        foreach ($posts as $post) {
            if ($this->syncPost($post)) {
                $marked++;
            }
        }

        return $marked;
    }

    private function syncPost(Post $post): bool
    {
        $workspace = $post->workspace;

        if ($workspace === null) {
            return false;
        }

        $record = $post->postsyncer ?? [];
        $groups = is_array($record['groups'] ?? null) ? $record['groups'] : [];

        if ($groups === []) {
            return false;
        }

        $config = PostsyncerConfig::fromWorkspace($workspace);

        if (! $config->isConfigured()) {
            return false;
        }

        $client = new PostsyncerClient($config);
        $updated = [];
        $allPublished = true;
        $anyChecked = false;

        foreach ($groups as $group) {
            if (! is_array($group)) {
                $allPublished = false;

                continue;
            }

            $postId = $group['post_id'] ?? '';

            if (! is_scalar($postId) || (string) $postId === '') {
                $updated[] = $group;
                $allPublished = false;

                continue;
            }

            try {
                $live = $client->getPost((string) $postId);
            } catch (PostsyncerException) {
                $updated[] = $group;
                $allPublished = false;

                continue;
            }

            $status = strtoupper((string) ($live['status'] ?? $group['status'] ?? ''));
            $group['status'] = $status;

            if (isset($live['scheduled_at']) && is_scalar($live['scheduled_at'])) {
                $group['scheduled_at'] = (string) $live['scheduled_at'];
            }

            if (isset($live['published_at']) && is_scalar($live['published_at'])) {
                $group['published_at'] = (string) $live['published_at'];
            }

            $updated[] = $group;
            $anyChecked = true;

            if ($status !== 'PUBLISHED') {
                $allPublished = false;
            }
        }

        $values = [
            'postsyncer' => array_merge($record, ['groups' => $updated]),
        ];

        if ($anyChecked && $allPublished) {
            $values['status'] = 'posted';
        }

        $post->forceFill($values)->save();

        return $anyChecked && $allPublished;
    }
}
