<?php

namespace App\Support\Socialreins;

use App\Models\Post;
use App\Models\SocialreinsGroup;
use App\Models\Video;
use Illuminate\Support\Collection;

/**
 * Reads and updates the per-post results kept on a SocialreinsGroup:
 * `{brain post id: {platform, status, error, targets: {target id: {status, remote_url, error}}}}`.
 * Brain's post status is not enough on its own (a post can settle `published` while one
 * target failed), so the targets decide when there are any.
 */
final class SocialreinsResults
{
    public const OK = 'ok';

    public const FAILED = 'failed';

    public const WAITING = 'waiting';

    public const DELETED = 'deleted';

    /** @return list<SocialreinsGroup> */
    public static function rowsOf(Post|Video $subject): array
    {
        return array_values(SocialreinsGroup::query()
            ->where('subject_type', $subject instanceof Video ? 'video' : 'post')
            ->where('subject_id', $subject->getKey())
            ->orderBy('sequence')
            ->get()
            ->all());
    }

    /**
     * One brain post (a `POST /post-groups` or `GET /posts` element) as a stored result.
     *
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>
     */
    public static function fromBrainPost(array $post): array
    {
        $targets = [];
        foreach (is_array($post['targets'] ?? null) ? $post['targets'] : [] as $target) {
            if (is_array($target) && is_string($target['id'] ?? null)) {
                $targets[$target['id']] = self::target($target);
            }
        }

        return [
            'platform' => PostGroupPayloadBuilder::cmPlatform((string) ($post['platform'] ?? '')),
            'status' => (string) ($post['status'] ?? 'scheduled'),
            'error' => is_string($post['error'] ?? null) ? $post['error'] : null,
            'targets' => $targets,
        ];
    }

    /**
     * @param  array<string, mixed>  $target  a brain target or a webhook `data` block
     * @return array{status: string, remote_url: string|null, error: string|null}
     */
    public static function target(array $target): array
    {
        return [
            'status' => (string) ($target['status'] ?? 'pending'),
            'remote_url' => is_string($target['remote_url'] ?? null) ? $target['remote_url'] : null,
            'error' => is_string($target['error'] ?? null) ? $target['error'] : null,
        ];
    }

    /**
     * Fold a `post.target.*` webhook payload into a group's results.
     *
     * @param  array<string, array<string, mixed>>  $results
     * @param  array<string, mixed>  $data
     * @return array<string, array<string, mixed>>|null null when the post is not in the group
     */
    public static function withTargetEvent(array $results, array $data): ?array
    {
        $postId = $data['post_id'] ?? null;
        $targetId = $data['target_id'] ?? null;

        if (! is_string($postId) || ! is_string($targetId) || ! isset($results[$postId])) {
            return null;
        }

        $results[$postId]['targets'][$targetId] = self::target($data);

        return $results;
    }

    /**
     * Where each platform stands: the newest created group wins, so a retry of a failed
     * platform supersedes its earlier failure.
     *
     * @param  list<SocialreinsGroup>  $rows
     * @return array<string, array{state: string, detail: string, post_id: string, language: string}> keyed "language:platform"
     */
    public static function byPlatform(array $rows): array
    {
        $platforms = [];

        foreach ($rows as $row) {
            if ($row->state !== SocialreinsGroup::CREATED) {
                continue;
            }

            foreach ($row->results ?? [] as $postId => $result) {
                $platforms[$row->language.':'.$result['platform']] = self::classify((string) $postId, $result, $row->language);
            }
        }

        return $platforms;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{state: string, detail: string, post_id: string, language: string}
     */
    private static function classify(string $postId, array $result, string $language): array
    {
        $statuses = [];
        $details = [];
        $targets = is_array($result['targets'] ?? null) ? $result['targets'] : [];

        if ($targets === []) {
            $statuses[] = (string) $result['status'];
            $details[] = (string) ($result['error'] ?? '');
        }
        foreach ($targets as $target) {
            $statuses[] = (string) $target['status'];
            $details[] = (string) ($target['error'] ?? '');
        }

        $state = match (true) {
            in_array('failed', $statuses, true) => self::FAILED,
            array_filter($statuses, fn (string $s): bool => ! in_array($s, ['published', 'deleted'], true)) !== [] => self::WAITING,
            array_filter($statuses, fn (string $s): bool => $s === 'published') !== [] => self::OK,
            default => self::DELETED,
        };

        return [
            'state' => $state,
            'detail' => implode('; ', array_filter($details)),
            'post_id' => $postId,
            'language' => $language,
        ];
    }

    /**
     * @param  array<string, array{state: string, detail: string, post_id: string, language: string}>  $platforms
     * @return Collection<string, array{state: string, detail: string, post_id: string, language: string}>
     */
    public static function withState(array $platforms, string $state): Collection
    {
        return collect($platforms)->filter(fn (array $p): bool => $p['state'] === $state);
    }
}
