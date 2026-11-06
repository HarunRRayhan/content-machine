<?php

namespace App\Actions\Socialreins;

use App\Models\Post;
use App\Models\SocialreinsGroup;
use App\Models\Video;
use App\Support\Socialreins\SocialreinsClient;
use App\Support\Socialreins\SocialreinsConfig;
use App\Support\Socialreins\SocialreinsException;
use App\Support\Socialreins\SocialreinsResults;

/**
 * Takes a Post or Video down from SocialReins: `DELETE /posts/{id}` for every brain post
 * that is live, scheduled or a draft. Each call has an Idempotency-Key; a refused call
 * (brain stores the 4xx) moves to the next key so the retry actually tries again.
 *
 * It never reports success while something may still be live or publishing:
 * - a request that got no answer may have created a group CM does not know: refused until a
 *   publish retry replays it and returns the first result;
 * - a post that is publishing (queued) cannot be deleted yet;
 * - a post that failed after part of it went out (another account, thread parts) cannot be
 *   deleted by brain at all: it is reported, not skipped;
 * - a post that failed with nothing live has nothing to take down and is skipped.
 */
class TakeDownSocialreinsAction
{
    /** @return list<string> what could not be taken down; empty when everything is down */
    public function handle(Post|Video $subject): array
    {
        $rows = SocialreinsResults::rowsOf($subject);
        $problems = [];

        foreach ($rows as $row) {
            if ($row->state === SocialreinsGroup::PENDING) {
                $problems[] = "{$row->language}: SocialReins never answered the request that publishes this, so CM does not know "
                    .'whether it created posts. Status unknown: retry the publish first (it replays the same request and '
                    .'returns the first result), then take it down.';
            }
        }

        foreach (SocialreinsResults::byPlatform($rows) as $key => $platform) {
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
            // A query, not the held model: its copy of publish_state may be stale.
            $subject::query()->whereKey($subject->getKey())->update([
                'status' => $subject instanceof Video ? 'recorded' : 'ready',
                'publish_state' => 'idle',
                'publish_error' => null,
                'publish_progress' => null,
                'updated_at' => now(),
            ]);
        }

        return $problems;
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
