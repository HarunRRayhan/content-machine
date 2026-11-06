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
 * that is still up (a published post is taken down from its accounts, a draft or scheduled
 * one is removed). Each call has an Idempotency-Key; a refused call (brain stores the 4xx)
 * moves to the next key so the retry actually tries again.
 */
class TakeDownSocialreinsAction
{
    /** @return list<string> what could not be taken down; empty when everything is down */
    public function handle(Post|Video $subject): array
    {
        $rows = SocialreinsResults::rowsOf($subject);
        $problems = [];

        foreach (SocialreinsResults::byPlatform($rows) as $key => $platform) {
            if ($platform['state'] === SocialreinsResults::DELETED) {
                continue;
            }

            $row = $this->rowOf($rows, $platform['post_id']);
            $name = explode(':', $key, 2)[1];

            try {
                $this->delete($row, $platform['post_id']);
            } catch (SocialreinsException $exception) {
                $problems[] = "{$name}: ".$exception->getMessage();
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

    private function delete(SocialreinsGroup $row, string $postId): void
    {
        $client = new SocialreinsClient(SocialreinsConfig::fromWorkspace($row->workspace), $row->language);
        $results = $row->results ?? [];
        $attempt = (int) ($results[$postId]['delete_attempt'] ?? 1);

        try {
            $client->deletePost($postId, "cm:ws{$row->workspace_id}:del:{$postId}:{$attempt}");
        } catch (SocialreinsException $exception) {
            // Already gone counts as down. Any other stored refusal moves to a new key.
            if ($exception->status !== 404) {
                if ($exception->isDefinitive()) {
                    $results[$postId]['delete_attempt'] = $attempt + 1;
                    $row->forceFill(['results' => $results])->save();
                }

                throw $exception;
            }
        }

        $results[$postId]['status'] = 'deleted';
        foreach (array_keys($results[$postId]['targets'] ?? []) as $targetId) {
            $results[$postId]['targets'][$targetId]['status'] = 'deleted';
        }
        $row->forceFill(['results' => $results])->save();
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
