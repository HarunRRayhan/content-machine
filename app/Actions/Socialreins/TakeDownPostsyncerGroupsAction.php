<?php

namespace App\Actions\Socialreins;

use App\Models\Post;
use App\Models\Video;
use App\Support\Postsyncer\PostsyncerClient;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Postsyncer\PostsyncerException;
use Illuminate\Support\Facades\DB;

/**
 * The PostSyncer take down: the half of a split take down, and the first step of a move off
 * PostSyncer. Each group is read first and deleted only while it is still scheduled or a
 * draft; a 404 counts as already gone once the same run has seen the key work. Each deletion is recorded at once as `DELETED`.
 */
class TakeDownPostsyncerGroupsAction
{
    /**
     * Deletes each still-scheduled PostSyncer group of a post or video. A published one is reported:
     * PostSyncer does not say its delete removes a live post, so the owner removes it there.
     *
     * @return list<string> what could not be taken down
     */
    public function handle(Post|Video $post): array
    {
        $client = new PostsyncerClient(PostsyncerConfig::fromWorkspace($post->workspace));
        $problems = [];
        // A 404 means "already gone" only once this run has seen the key work: a revoked key or a
        // cancelled account can answer 404 too, and the post would still go out.
        $keyWorks = false;

        foreach ($post->postsyncer['groups'] ?? [] as $group) {
            $postId = (string) ($group['post_id'] ?? '');
            $name = 'PostSyncer '.implode(', ', (array) ($group['platforms'] ?? []));
            if ($postId === '' || ($group['status'] ?? null) === 'DELETED') {
                continue;
            }

            try {
                $remote = $client->getPost($postId);
                $keyWorks = true;
                $status = strtoupper((string) ((is_array($remote['data'] ?? null) ? $remote['data'] : $remote)['status'] ?? ''));

                if (! in_array($status, ['SCHEDULED', 'DRAFT'], true)) {
                    $problems[] = "{$name}: {$status} in PostSyncer (post {$postId}), which cannot take a published post off the platform. "
                        .'Remove it on the platform and delete it in PostSyncer, then take down again.';

                    continue;
                }

                $client->deletePost($postId);
            } catch (PostsyncerException $exception) {
                // Already gone counts as down.
                if ($exception->getCode() !== 404) {
                    $problems[] = "{$name}: ".$exception->getMessage();

                    continue;
                }
                if (! $keyWorks && ! ($keyWorks = $this->keyWorks($client))) {
                    $problems[] = "{$name}: PostSyncer answered 404 for post {$postId}, but its API key could not be confirmed, "
                        .'so the post may still be scheduled. Check the key in Settings > PostSyncer.';

                    continue;
                }
            }

            $this->markPostsyncerGroupDeleted($post, $postId);
        }

        return $problems;
    }

    /** One cheap authenticated call. */
    private function keyWorks(PostsyncerClient $client): bool
    {
        try {
            $client->listWorkspaces();

            return true;
        } catch (PostsyncerException) {
            return false;
        }
    }

    /** Recorded at once, under the row lock, so a retry skips it and nothing done is forgotten. */
    private function markPostsyncerGroupDeleted(Post|Video $post, string $postId): void
    {
        DB::transaction(function () use ($post, $postId): void {
            $locked = $post::query()->whereKey($post->getKey())->lockForUpdate()->firstOrFail();
            $postsyncer = $locked->postsyncer;
            foreach ($postsyncer['groups'] ?? [] as $i => $group) {
                if ((string) ($group['post_id'] ?? '') === $postId) {
                    $postsyncer['groups'][$i]['status'] = 'DELETED';
                }
            }
            $locked->forceFill(['postsyncer' => $postsyncer])->save();
        });
    }
}
