<?php

namespace App\Actions\Socialreins;

use App\Models\Post;
use App\Models\SocialreinsGroup;
use App\Models\Video;
use App\Support\Postsyncer\PostsyncerClient;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Postsyncer\PostsyncerException;
use App\Support\Socialreins\SocialreinsClient;
use App\Support\Socialreins\SocialreinsConfig;
use App\Support\Socialreins\SocialreinsException;
use App\Support\Socialreins\SocialreinsResults;
use Illuminate\Support\Facades\DB;

/**
 * Takes a Post or Video down from SocialReins: `DELETE /posts/{id}` for every brain post
 * that is live, scheduled or a draft. Each call has an Idempotency-Key; a refused call
 * (brain stores the 4xx) moves to the next key so the retry actually tries again.
 *
 * It never reports success while something may still be live or publishing:
 * - a request that got no answer is looked up by its Idempotency-Key first; if brain cannot say
 *   (still processing, unreachable) the take down is refused;
 * - a post that is publishing (queued) cannot be deleted yet;
 * - a post that failed after part of it went out (another account, thread parts) cannot be
 *   deleted by brain at all: it is reported, not skipped;
 * - a post that failed with nothing live has nothing to take down and is skipped.
 *
 * A split subject's PostSyncer half is taken down too (takeDownPostsyncerHalf()), and the
 * subject resets only once both halves are down.
 */
class TakeDownSocialreinsAction
{
    public function __construct(
        private readonly ResolveSocialreinsGroupAction $resolve,
    ) {}

    /** @return list<string> what could not be taken down; empty when everything is down */
    public function handle(Post|Video $subject): array
    {
        $split = is_array($subject->publish_progress['split'] ?? null);

        // A hand-over still running could create SocialReins posts after this returns.
        if ($split && (SocialreinsResults::rowsOf($subject) === [] || in_array($subject->publish_state, ['queued', 'running'], true))) {
            return ['The SocialReins half is still being handed over. Try again when the publish settles.'];
        }

        $problems = $split ? $this->takeDownPostsyncerHalf($subject) : [];

        // A request that got no answer may have created a group CM does not know: ask brain
        // what its key made before anything else.
        foreach (SocialreinsResults::rowsOf($subject) as $row) {
            if ($row->state === SocialreinsGroup::PENDING) {
                $outcome = $this->resolve->handle($row);

                if ($outcome === ResolveSocialreinsGroupAction::MISSING) {
                    // Brain stored nothing under the key: the request never ran, nothing exists.
                    $row->forceFill(['state' => SocialreinsGroup::REJECTED, 'error' => 'SocialReins never received the request.'])->save();
                } elseif ($outcome === ResolveSocialreinsGroupAction::PENDING) {
                    $problems[] = "{$row->language}: status unknown. ".($row->fresh()->error ?? 'SocialReins could not be asked.')
                        .' Try again shortly; CM does not report a take down while a group may exist.';
                }
            }
        }

        $rows = SocialreinsResults::rowsOf($subject);

        // Classified as brain reports each post, whether or not the whole group has reported yet.
        foreach (SocialreinsResults::byPlatform($rows, requireComplete: false) as $key => $platform) {
            $name = explode(':', $key, 2)[1];
            $row = $this->rowOf($rows, $platform['post_id']);
            $result = $row->results[$platform['post_id']];

            $problem = match ($platform['state']) {
                SocialreinsResults::DELETED, SocialreinsResults::FAILED => null,
                SocialreinsResults::PARTIAL => "{$name}: partly published and failed in SocialReins (group {$platform['group_id']}), "
                    .'which cannot delete a failed post. Take the live parts down in SocialReins or the platform app.',
                default => $this->isPublishing($result)
                    ? "{$name}: still publishing in SocialReins, which cannot delete a queued post. Try again when it settles."
                    : $this->delete($row, $platform['post_id'], $name),
            };

            if ($problem !== null) {
                $problems[] = $problem;
            }
        }

        if ($problems === [] && $rows !== []) {
            $postsyncer = $split ? $subject->fresh()?->postsyncer : null;
            // A query, not the held model: its copy of publish_state may be stale.
            $subject::query()->whereKey($subject->getKey())->update([
                'status' => $subject instanceof Video ? 'recorded' : 'ready',
                'publish_state' => 'idle',
                'publish_error' => null,
                'publish_progress' => null,
                // A split subject's PostSyncer half is gone too: keep its groups for audit only, so the
                // post can be published again (as ReconcileVideoGroupsDeletedAction does).
                ...($split ? ['postsyncer' => json_encode(['groups' => [], 'cleared_groups' => [
                    ...($postsyncer['cleared_groups'] ?? []),
                    ...($postsyncer['groups'] ?? []),
                ]])] : []),
                'updated_at' => now(),
            ]);
        }

        return $problems;
    }

    /**
     * Deletes each still-scheduled PostSyncer group of a split post or video. A published one is reported:
     * PostSyncer does not say its delete removes a live post, so the owner removes it there.
     *
     * @return list<string> what could not be taken down
     */
    private function takeDownPostsyncerHalf(Post|Video $post): array
    {
        $client = new PostsyncerClient(PostsyncerConfig::fromWorkspace($post->workspace));
        $problems = [];

        foreach ($post->postsyncer['groups'] ?? [] as $group) {
            $postId = (string) ($group['post_id'] ?? '');
            $name = 'PostSyncer '.implode(', ', (array) ($group['platforms'] ?? []));
            if ($postId === '' || ($group['status'] ?? null) === 'DELETED') {
                continue;
            }

            try {
                $remote = $client->getPost($postId);
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
            }

            $this->markPostsyncerGroupDeleted($post, $postId);
        }

        return $problems;
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

    /** @param  array<string, mixed>  $result */
    private function isPublishing(array $result): bool
    {
        return $result['status'] === 'queued'
            || array_filter($result['targets'] ?? [], fn (array $t): bool => in_array($t['status'], ['pending', 'publishing'], true)) !== [];
    }

    /** @return string|null why it is not down, or null once it is */
    private function delete(SocialreinsGroup $row, string $postId, string $name): ?string
    {
        $client = new SocialreinsClient(SocialreinsConfig::fromWorkspace($row->workspace), $row->language);
        $attempt = (int) ($row->results[$postId]['delete_attempt'] ?? 1);

        try {
            $client->deletePost($postId, "cm:ws{$row->workspace_id}:del:{$postId}:{$attempt}");
        } catch (SocialreinsException $exception) {
            // Already gone counts as down; anything else is reported.
            if ($exception->status !== 404) {
                // Brain stored the refusal under this key, so the next try needs a new one.
                if ($exception->isDefinitive()) {
                    $row->mutateResults(function (array $results) use ($postId, $attempt): array {
                        $results[$postId]['delete_attempt'] = $attempt + 1;

                        return $results;
                    });
                }

                return $exception->errorCode === 'not_deletable'
                    ? "{$name}: SocialReins cannot delete it right now (queued or failed there). Try again later."
                    : "{$name}: ".$exception->getMessage();
            }
        }

        $row->mutateResults(function (array $results) use ($postId): array {
            $results[$postId]['status'] = 'deleted';
            $results[$postId]['at'] = time();
            foreach (array_keys($results[$postId]['targets'] ?? []) as $targetId) {
                $results[$postId]['targets'][$targetId]['status'] = 'deleted';
            }

            return $results;
        });

        return null;
    }

    /** @param  list<SocialreinsGroup>  $rows */
    private function rowOf(array $rows, string $postId): SocialreinsGroup
    {
        foreach ($rows as $row) {
            if (isset($row->results[$postId])) {
                return $row;
            }
        }

        throw new SocialreinsException("Post {$postId} is not in any stored group.");
    }
}
