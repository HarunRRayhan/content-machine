<?php

namespace App\Support\Socialreins;

use App\Models\Post;
use App\Models\SocialreinsGroup;
use App\Models\Video;
use Illuminate\Support\Collection;

/**
 * Reads and merges the per-post results kept on a SocialreinsGroup:
 * `{brain post id: {platform, status, error, at, targets: {target id: {status, remote_id,
 * remote_url, parts, error, at}}}}`.
 *
 * Brain's post status alone is not enough (a post can settle `published` while one target
 * failed), and a webhook only reports the target it is about, so a post counts as settled
 * only when a snapshot (the create answer or `GET /posts?group_id=`) says the post itself
 * is settled and every target we know is terminal. Merges never move a target backwards,
 * so a late older snapshot cannot overwrite a newer webhook.
 */
final class SocialreinsResults
{
    public const OK = 'ok';

    public const FAILED = 'failed';

    /** Part of the platform is live or has partial thread parts: never to be resent. */
    public const PARTIAL = 'partial';

    public const WAITING = 'waiting';

    public const DELETED = 'deleted';

    private const RANK = [
        'draft' => 0, 'pending' => 0, 'scheduled' => 0,
        'queued' => 1, 'publishing' => 1,
        'published' => 2, 'failed' => 2,
        'deleted' => 3,
    ];

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
     * @param  int  $at  when the snapshot was taken (before the request was sent)
     * @return array<string, mixed>
     */
    public static function fromBrainPost(array $post, int $at): array
    {
        $targets = [];
        foreach (is_array($post['targets'] ?? null) ? $post['targets'] : [] as $target) {
            if (is_array($target) && is_string($target['id'] ?? null)) {
                $targets[$target['id']] = self::target($target, $at);
            }
        }

        return [
            'platform' => PostGroupPayloadBuilder::cmPlatform((string) ($post['platform'] ?? '')),
            'status' => (string) ($post['status'] ?? 'scheduled'),
            'error' => is_string($post['error'] ?? null) ? $post['error'] : null,
            'at' => $at,
            'targets' => $targets,
        ];
    }

    /**
     * @param  array<string, mixed>  $target  a brain target or a webhook `data` block
     * @return array{status: string, remote_id: string|null, remote_url: string|null, parts: int, error: string|null, at: int}
     */
    public static function target(array $target, int $at): array
    {
        return [
            'status' => (string) ($target['status'] ?? 'pending'),
            'remote_id' => is_string($target['remote_id'] ?? null) ? $target['remote_id'] : null,
            'remote_url' => is_string($target['remote_url'] ?? null) ? $target['remote_url'] : null,
            'parts' => is_array($target['parts'] ?? null) ? count($target['parts']) : 0,
            'error' => is_string($target['error'] ?? null) ? $target['error'] : null,
            'at' => $at,
        ];
    }

    /**
     * Fold a snapshot of one post into what is stored, never moving anything backwards.
     *
     * @param  array<string, mixed>|null  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    public static function mergePost(?array $existing, array $incoming): array
    {
        if ($existing === null) {
            return $incoming;
        }

        $merged = $existing;
        if (self::newer($incoming['status'], (int) $incoming['at'], $existing['status'], (int) ($existing['at'] ?? 0))) {
            $merged['status'] = $incoming['status'];
            $merged['error'] = $incoming['error'];
            $merged['at'] = $incoming['at'];
        }

        foreach ($incoming['targets'] as $id => $target) {
            $merged['targets'][$id] = self::mergeTarget($existing['targets'][$id] ?? null, $target);
        }

        return $merged;
    }

    /**
     * Fold a `post.target.*` webhook payload into a group's results.
     *
     * @param  array<string, array<string, mixed>>  $results
     * @param  array<string, mixed>  $data
     * @return array<string, array<string, mixed>>|null null when the post is not in the group
     */
    public static function withTargetEvent(array $results, array $data, int $at): ?array
    {
        $postId = $data['post_id'] ?? null;
        $targetId = $data['target_id'] ?? null;

        if (! is_string($postId) || ! is_string($targetId) || ! isset($results[$postId])) {
            return null;
        }

        $results[$postId]['targets'][$targetId] = self::mergeTarget(
            $results[$postId]['targets'][$targetId] ?? null,
            self::target($data, $at),
        );

        return $results;
    }

    /**
     * @param  array<string, mixed>|null  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private static function mergeTarget(?array $existing, array $incoming): array
    {
        if ($existing === null) {
            return $incoming;
        }

        if (! self::newer($incoming['status'], (int) $incoming['at'], $existing['status'], (int) $existing['at'])) {
            return $existing;
        }

        // A webhook says nothing of remote ids or parts: keep what a snapshot learned.
        $incoming['remote_id'] ??= $existing['remote_id'] ?? null;
        $incoming['remote_url'] ??= $existing['remote_url'] ?? null;
        $incoming['parts'] = max((int) $incoming['parts'], (int) ($existing['parts'] ?? 0));

        return $incoming;
    }

    private static function newer(string $incoming, int $incomingAt, string $existing, int $existingAt): bool
    {
        $a = self::RANK[$incoming] ?? 0;
        $b = self::RANK[$existing] ?? 0;

        return $a > $b || ($a === $b && $incomingAt >= $existingAt);
    }

    /**
     * Where each platform stands: the newest created group wins, so a resend of a failed
     * platform supersedes its earlier failure.
     *
     * @param  list<SocialreinsGroup>  $rows
     * @return array<string, array{state: string, detail: string, post_id: string, language: string, group_id: string|null}> keyed "language:platform"
     */
    public static function byPlatform(array $rows): array
    {
        $platforms = [];

        foreach ($rows as $row) {
            if ($row->state !== SocialreinsGroup::CREATED) {
                continue;
            }

            foreach ($row->results ?? [] as $postId => $result) {
                $platforms[$row->language.':'.$result['platform']] = [
                    ...self::classify($result),
                    'post_id' => (string) $postId,
                    'language' => $row->language,
                    'group_id' => $row->group_id,
                ];
            }
        }

        return $platforms;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{state: string, detail: string}
     */
    public static function classify(array $result): array
    {
        $status = (string) $result['status'];
        $targets = is_array($result['targets'] ?? null) ? $result['targets'] : [];
        $statuses = array_column($targets, 'status');
        $details = array_filter(array_merge([(string) ($result['error'] ?? '')], array_column($targets, 'error')));
        $detail = implode('; ', array_unique($details));

        $unsettled = in_array($status, ['draft', 'scheduled', 'queued'], true)
            || array_filter($statuses, fn (string $s): bool => in_array($s, ['pending', 'publishing'], true)) !== [];

        if ($status === 'deleted' || ($statuses !== [] && array_unique($statuses) === ['deleted'])) {
            return ['state' => self::DELETED, 'detail' => $detail];
        }

        if ($unsettled) {
            return ['state' => self::WAITING, 'detail' => $detail];
        }

        $published = count(array_filter($statuses, fn (string $s): bool => $s === 'published'));
        $failed = array_filter($targets, fn (array $t): bool => $t['status'] === 'failed');
        // A failed target that already has a remote id or thread parts is partly live.
        $touched = array_filter($failed, fn (array $t): bool => ($t['remote_id'] ?? null) !== null || (int) ($t['parts'] ?? 0) > 0);

        return match (true) {
            $failed === [] && $status !== 'failed' => ['state' => self::OK, 'detail' => $detail],
            $published > 0 || $touched !== [] => ['state' => self::PARTIAL, 'detail' => $detail],
            default => ['state' => self::FAILED, 'detail' => $detail],
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $platforms
     * @return Collection<string, array<string, mixed>>
     */
    public static function withState(array $platforms, string $state): Collection
    {
        /** @var Collection<string, array<string, mixed>> $found */
        $found = new Collection;
        foreach ($platforms as $key => $platform) {
            if ($platform['state'] === $state) {
                $found->put($key, $platform);
            }
        }

        return $found;
    }
}
