<?php

namespace App\Actions\Postsyncer;

use App\Models\Post;
use App\Models\Video;
use App\Support\Postsyncer\PostsyncerClient;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Postsyncer\PostsyncerException;

/**
 * Live-check scheduled posts and videos against PostSyncer and mark them
 * posted once every stored group is terminal: PUBLISHED, or FAILED with at
 * least one sibling PUBLISHED (English Instagram often reports FAILED while
 * already live; waiting forever leaves the CM row stuck on Scheduled).
 */
class SyncScheduledPostsAction
{
    /** @var list<string> */
    private const TERMINAL_GROUP_STATUSES = ['PUBLISHED', 'FAILED'];

    /**
     * @return array{posts: int, videos: int}
     */
    public function handle(): array
    {
        return [
            'posts' => $this->syncPosts(),
            'videos' => $this->syncVideos(),
        ];
    }

    private function syncPosts(): int
    {
        $marked = 0;

        $posts = Post::query()
            ->where('status', 'scheduled')
            ->with('workspace')
            ->get();

        foreach ($posts as $post) {
            if ($this->syncRecord($post)) {
                $marked++;
            }
        }

        return $marked;
    }

    private function syncVideos(): int
    {
        $marked = 0;

        $videos = Video::query()
            ->where('status', 'scheduled')
            ->with('workspace')
            ->get();

        foreach ($videos as $video) {
            if ($this->syncRecord($video)) {
                $marked++;
            }
        }

        return $marked;
    }

    private function syncRecord(Post|Video $record): bool
    {
        $workspace = $record->workspace;

        if ($workspace === null) {
            return false;
        }

        $stored = $record->postsyncer ?? [];
        $groups = is_array($stored['groups'] ?? null) ? $stored['groups'] : [];

        if ($groups === []) {
            return false;
        }

        $config = PostsyncerConfig::fromWorkspace($workspace);

        if (! $config->isConfigured()) {
            return false;
        }

        $client = new PostsyncerClient($config);
        $updated = [];
        $allTerminal = true;
        $anyPublished = false;
        $anyChecked = false;

        foreach ($groups as $group) {
            if (! is_array($group)) {
                $allTerminal = false;

                continue;
            }

            $postId = $group['post_id'] ?? '';

            if (! is_scalar($postId) || (string) $postId === '') {
                $updated[] = $group;
                $allTerminal = false;

                continue;
            }

            try {
                $live = $client->getPost((string) $postId);
            } catch (PostsyncerException) {
                $updated[] = $group;
                $allTerminal = false;

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

            if ($status === 'PUBLISHED') {
                $anyPublished = true;
            }

            if (! in_array($status, self::TERMINAL_GROUP_STATUSES, true)) {
                $allTerminal = false;
            }
        }

        $shouldMarkPosted = $anyChecked && $allTerminal && $anyPublished;

        $values = [
            'postsyncer' => array_merge($stored, ['groups' => $updated]),
        ];

        if ($shouldMarkPosted) {
            $values['status'] = 'posted';
        }

        $record->forceFill($values)->save();

        return $shouldMarkPosted;
    }
}
