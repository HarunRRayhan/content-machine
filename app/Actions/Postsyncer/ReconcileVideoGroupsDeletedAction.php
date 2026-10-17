<?php

namespace App\Actions\Postsyncer;

use App\Models\Video;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerClient;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Postsyncer\PostsyncerException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * After the owner deletes a video's PostSyncer posts, clear the stored groups
 * so the normal publish route can schedule the video again. Only runs when a
 * read-only GET confirms every stored post is gone (404). With
 * $ownerRemovedPublished, a post still PUBLISHED is also accepted, because the
 * owner has confirmed the live content was removed from the platforms.
 * Cleared groups are kept under postsyncer.cleared_groups for audit.
 */
final class ReconcileVideoGroupsDeletedAction
{
    public function handle(Video $video, Workspace $workspace, bool $ownerRemovedPublished = false): Video
    {
        abort_if($video->workspace_id !== $workspace->id, 404);

        $video->refresh();

        if (! in_array($video->status, ['scheduled', 'posted'], true)) {
            throw new PostsyncerException('Only scheduled or posted videos can be reset after their posts are deleted.');
        }

        if ($video->publish_state !== 'succeeded' || $video->hasUncertainPublish()) {
            throw new PostsyncerException('The video has a publish in progress or an uncertain publish. Reconcile it first.');
        }

        $reschedule = is_array($video->publish_progress) ? ($video->publish_progress['reschedule'] ?? null) : null;
        if (is_array($reschedule) && ($reschedule['state'] ?? null) === 'running') {
            throw new PostsyncerException('A reschedule is running for this video. Wait for it to finish first.');
        }

        $groups = $video->postsyncer['groups'] ?? null;
        if (! is_array($groups) || $groups === []) {
            throw new PostsyncerException('This video has no PostSyncer groups to reconcile.');
        }

        $config = PostsyncerConfig::fromWorkspace($workspace);
        $client = new PostsyncerClient($config);

        $verified = [];
        foreach ($groups as $group) {
            $postId = (string) ($group['post_id'] ?? '');
            if ($postId === '') {
                throw new PostsyncerException('A stored PostSyncer group has no post id.');
            }

            $verified[] = [
                'group' => $group,
                'remote_status' => $this->assertCleared($client, $postId, $ownerRemovedPublished),
            ];
        }

        DB::transaction(function () use ($video, $groups, $verified, $ownerRemovedPublished): void {
            $locked = Video::query()->whereKey($video->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isPublishInProgress() || $locked->publish_state !== 'succeeded') {
                throw new PostsyncerException('The video publish state changed while reconciling. Retry.');
            }

            if (($locked->postsyncer['groups'] ?? null) !== $groups) {
                throw new PostsyncerException('The stored PostSyncer groups changed while reconciling. Retry.');
            }

            $postsyncer = $locked->postsyncer;
            $clearedAt = now()->toIso8601String();
            $cleared = is_array($postsyncer['cleared_groups'] ?? null) ? $postsyncer['cleared_groups'] : [];

            foreach ($verified as $entry) {
                $cleared[] = [
                    'group' => $entry['group'],
                    'remote_status' => $entry['remote_status'],
                    'owner_removed_published' => $ownerRemovedPublished,
                    'cleared_at' => $clearedAt,
                ];
            }

            $locked->forceFill([
                'status' => 'recorded',
                'publish_state' => 'idle',
                'publish_error' => null,
                'publish_progress' => null,
                'postsyncer' => ['groups' => [], 'cleared_groups' => $cleared],
            ])->save();
        });

        return $video->fresh() ?? $video;
    }

    /**
     * @return string 'DELETED' when PostSyncer returns 404, otherwise the live status ('PUBLISHED')
     */
    private function assertCleared(PostsyncerClient $client, string $postId, bool $ownerRemovedPublished): string
    {
        try {
            $remote = $client->getPost($postId);
        } catch (PostsyncerException $exception) {
            if ($exception->getCode() === 404) {
                return 'DELETED';
            }

            throw new PostsyncerException(
                "Could not confirm PostSyncer post {$postId} is deleted: {$exception->getMessage()}",
                0,
                $exception,
            );
        } catch (Throwable $exception) {
            throw new PostsyncerException(
                "Could not confirm PostSyncer post {$postId} is deleted.",
                0,
                $exception,
            );
        }

        $body = is_array($remote['data'] ?? null) ? $remote['data'] : $remote;
        $status = strtoupper((string) ($body['status'] ?? ''));

        if ($ownerRemovedPublished && $status === 'PUBLISHED') {
            return 'PUBLISHED';
        }

        throw new PostsyncerException("PostSyncer post {$postId} still exists. Delete it in PostSyncer first.");
    }
}
