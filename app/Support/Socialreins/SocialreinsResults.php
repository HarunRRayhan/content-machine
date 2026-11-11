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

    /** Part of the platform is live (another account, thread parts); a retry continues the rest. */
    public const PARTIAL = 'partial';

    /** Every failed target may already have posted: brain will not retry it, the user checks. */
    public const UNKNOWN = 'unknown';

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
     * The SocialReins posts of a post or video in the PostSyncer group shape (one per language and
     * platform, the newest group winning), so the page lists both halves of a split the same way.
     *
     * @return list<array<string, mixed>>
     */
    public static function presentGroups(Post|Video $subject): array
    {
        $groups = [];

        foreach (self::rowsOf($subject) as $row) {
            if ($row->state !== SocialreinsGroup::CREATED) {
                continue;
            }

            $request = json_decode($row->request, true);
            $scheduledAt = is_array($request) && is_string($request['scheduled_at'] ?? null) ? $request['scheduled_at'] : null;

            foreach ($row->results ?? [] as $result) {
                $state = self::classify($result)['state'];

                $groups[$row->language.':'.$result['platform']] = [
                    'source' => 'socialreins',
                    // Brain's own status (scheduled, published) unless a target went wrong.
                    'status' => in_array($state, [self::FAILED, self::PARTIAL, self::UNKNOWN], true)
                        ? $state
                        : (string) $result['status'],
                    'scheduled_at' => $scheduledAt,
                    'platforms' => [$result['platform']],
                    'language' => $row->language,
                ];
            }
        }

        return array_values($groups);
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
     * @return array{status: string, remote_id: string|null, remote_url: string|null, parts: int, error: string|null, at: int, unknown: bool}
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
            'unknown' => false,
        ];
    }

    /**
     * Replace a post's stored result with the answer to a retry (taken after brain re-queued
     * the failed targets, so it is authoritative): everything older than `$at` is ignored from
     * now on. Targets named in `$unknown` were left alone because they may already have
     * posted.
     *
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     * @param  list<string>  $unknown  target ids brain skipped as outcome_unknown
     * @return array<string, mixed>
     */
    public static function afterRetry(array $existing, array $incoming, int $at, array $unknown): array
    {
        $incoming['reset_at'] = $at;
        foreach ($incoming['targets'] as $id => $target) {
            $incoming['targets'][$id]['reset_at'] = $at;
            $incoming['targets'][$id]['unknown'] = in_array($id, $unknown, true);
            $incoming['targets'][$id]['remote_id'] ??= $existing['targets'][$id]['remote_id'] ?? null;
            $incoming['targets'][$id]['parts'] = max($target['parts'], (int) ($existing['targets'][$id]['parts'] ?? 0));
        }
        if (isset($existing['delete_attempt'])) {
            $incoming['delete_attempt'] = $existing['delete_attempt'];
        }

        return $incoming;
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

        // Anything fetched before the last retry describes the attempt that was retried.
        if ((int) ($existing['reset_at'] ?? 0) > (int) $incoming['at']) {
            return $existing;
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

        // A webhook of the attempt that was retried (or a snapshot from before the retry).
        if ((int) ($existing['reset_at'] ?? 0) > (int) $incoming['at']) {
            return $existing;
        }

        if (! self::newer($incoming['status'], (int) $incoming['at'], $existing['status'], (int) $existing['at'])) {
            return $existing;
        }

        $incoming['reset_at'] = $existing['reset_at'] ?? null;
        $incoming['unknown'] = $incoming['status'] === 'failed' && ($existing['unknown'] ?? false);
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
     * @param  bool  $requireComplete  false to classify posts even before the group has fully reported
     * @return array<string, array{state: string, detail: string, post_id: string, language: string, group_id: string|null}> keyed "language:platform"
     */
    public static function byPlatform(array $rows, bool $requireComplete = true): array
    {
        $platforms = [];

        foreach ($rows as $row) {
            if ($row->state !== SocialreinsGroup::CREATED) {
                continue;
            }

            $complete = ! $requireComplete || self::groupComplete($row);

            foreach ($row->results ?? [] as $postId => $result) {
                $classified = self::classify($result);
                // Until every expected target has reported, nothing in the group is settled.
                if (! $complete) {
                    $classified['state'] = $classified['state'] === self::DELETED ? self::DELETED : self::WAITING;
                }
                $platforms[$row->language.':'.$result['platform']] = [
                    ...$classified,
                    'post_id' => (string) $postId,
                    'language' => $row->language,
                    'group_id' => $row->group_id,
                ];
            }
        }

        return $platforms;
    }

    /**
     * Brain's `expected_targets` (one per account of each post's platform in its set) against
     * the targets that reached a final state. A group read says how many to expect; without
     * one (the create answer, or a webhook alone) nothing can be called settled.
     */
    public static function groupComplete(SocialreinsGroup $row): bool
    {
        if ($row->expected_targets === null) {
            return false;
        }

        $final = 0;
        $postsFinal = true;
        $failedWithoutTargets = false;
        foreach ($row->results ?? [] as $result) {
            foreach ($result['targets'] ?? [] as $target) {
                $final += in_array($target['status'], ['published', 'failed', 'deleted'], true) ? 1 : 0;
            }
            $postsFinal = $postsFinal && in_array($result['status'], ['published', 'failed', 'deleted'], true);
            $failedWithoutTargets = $failedWithoutTargets
                || (($result['targets'] ?? []) === [] && in_array($result['status'], ['failed', 'deleted'], true));
        }

        // A post that failed before it had any target (no account, a bad request) has no
        // targets to count against the accounts brain expected for it.
        return $final >= $row->expected_targets || ($failedWithoutTargets && $postsFinal);
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

        // Every failed target may already have posted: brain will not retry them.
        if ($failed !== [] && array_filter($failed, fn (array $t): bool => ! ($t['unknown'] ?? false)) === []) {
            return ['state' => self::UNKNOWN, 'detail' => $detail];
        }

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
