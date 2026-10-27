<?php

namespace App\Actions\Postsyncer;

use App\Data\Postsyncer\ReschedulePostData;
use App\Models\Post;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerClient;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Postsyncer\PostsyncerException;
use App\Support\Postsyncer\PostsyncerPostVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Move already-scheduled PostSyncer groups without deleting and recreating them.
 *
 * Each external PUT is verified against the reviewed payload before it is sent
 * and against the new schedule after it returns. The checkpoint makes a lost
 * response safe to retry: a later call accepts a group already at the target
 * time and never sends a second create.
 */
final class ReschedulePostAction
{
    public function __construct(
        private readonly PostsyncerPostVerifier $verifier,
    ) {}

    public function handle(Post $post, Workspace $workspace, ReschedulePostData $data): Post
    {
        abort_if($post->workspace_id !== $workspace->id, 404);

        $post->refresh();

        if ($post->status !== 'scheduled') {
            throw new PostsyncerException('Only scheduled posts can be rescheduled.');
        }

        $existing = $this->rescheduleState($post);
        if (in_array($post->publish_state, ['queued', 'running'], true) && $existing === null) {
            throw new PostsyncerException('A publish is already in progress.');
        }

        $groups = $this->sourceGroups($post, $existing);
        $storedTarget = $this->parseStoredWhen($existing['target_when'] ?? null);
        $isUnfinished = $existing !== null
            && in_array($existing['state'] ?? null, ['running', 'failed'], true);

        // A partial reschedule checkpoint intentionally contains a mixture of
        // source-time and target-time payloads. Use the original schedule as
        // the timezone reference instead of requiring those payloads to match.
        $currentWhen = $isUnfinished
            ? ($this->parseStoredWhen($existing['source_when'] ?? null)
                ?? $this->payloadWhen($groups[0]['payload']))
            : $this->commonSchedule($groups);
        $targetWhen = $data->when->setTimezone($currentWhen->timezone)->setSecond(0);

        if ($targetWhen->lessThanOrEqualTo(CarbonImmutable::now($targetWhen->timezone))) {
            throw new PostsyncerException('The new schedule must be in the future.');
        }

        $config = PostsyncerConfig::fromWorkspace($workspace);
        if (! $config->isReadyForPublish()) {
            throw new PostsyncerException('PostSyncer is not configured for publishing.');
        }

        if ($isUnfinished) {
            if ($storedTarget === null || ! $this->sameMinute($storedTarget, $targetWhen)) {
                throw new PostsyncerException(
                    'A previous reschedule is unfinished. Retry it with the same target time first.',
                );
            }

            $state = $existing;
            $state['state'] = 'running';
            $state['error'] = null;
        } elseif ($existing !== null && ($existing['state'] ?? null) === 'succeeded') {
            if ($storedTarget !== null && $this->sameMinute($storedTarget, $targetWhen)) {
                return $post;
            }

            $state = $this->newState($existing['groups'], $targetWhen, $storedTarget ?? $currentWhen);
        } else {
            $state = $this->newState($groups, $targetWhen, $currentWhen);
        }

        $publicGroups = $this->publicGroups($post);
        $this->start($post, $state, $publicGroups);

        $client = new PostsyncerClient($config);

        try {
            foreach ($state['groups'] as $groupIndex => $group) {
                $state['current_index'] = $group['index'];
                $payload = $group['payload'];
                $sourceWhen = $this->payloadWhen($payload);
                $groupTarget = $targetWhen->setTimezone($sourceWhen->timezone)->setSecond(0);
                $targetPayload = $this->withSchedule($payload, $groupTarget);
                $postId = (string) $group['post_id'];

                $remote = $client->getPostWithAccountDetails($postId);
                $verified = $this->verifyAtTarget(
                    $remote,
                    $targetPayload,
                    $group['platforms'],
                    $groupTarget,
                    $postId,
                );

                if ($verified === null) {
                    $this->verifier->assertMatches(
                        $remote,
                        $payload,
                        $group['platforms'],
                        $sourceWhen,
                        $postId,
                    );

                    try {
                        $client->updatePost($postId, $targetPayload);
                    } catch (Throwable $updateException) {
                        if ($updateException instanceof PostsyncerException
                            && ! $updateException->outcomeUnknown) {
                            throw $updateException;
                        }

                        $verified = $this->verifyAfterUnknownUpdate(
                            $client,
                            $targetPayload,
                            $group['platforms'],
                            $groupTarget,
                            $postId,
                        );

                        if ($verified === null) {
                            throw new PostsyncerException(
                                'PostSyncer reschedule outcome is uncertain. Retry to reconcile the target schedule.',
                                0,
                                $updateException,
                                true,
                                true,
                            );
                        }
                    }

                    if ($verified === null) {
                        $verified = $this->verifier->assertMatches(
                            $client->getPostWithAccountDetails($postId),
                            $targetPayload,
                            $group['platforms'],
                            $groupTarget,
                            $postId,
                        );
                    }
                }

                $state['groups'][$groupIndex]['payload'] = $targetPayload;
                $state['groups'][$groupIndex]['scheduled_at'] = (string) ($verified['scheduled_at'] ?? '');
                $state['completed_indices'][] = (int) $group['index'];
                $state['completed_indices'] = array_values(array_unique($state['completed_indices']));
                $state['current_index'] = null;

                $publicGroups = $this->replacePublicGroup(
                    $publicGroups,
                    $postId,
                    $verified,
                );
                $this->checkpoint($post, $state, $publicGroups, 'running', null);
            }

            $state['state'] = 'succeeded';
            $state['current_index'] = null;
            $state['completed_at'] = CarbonImmutable::now()->toIso8601String();
            $this->checkpoint($post, $state, $publicGroups, 'succeeded', null);
        } catch (Throwable $exception) {
            $state['state'] = 'failed';
            $state['error'] = $exception->getMessage();

            try {
                $this->checkpoint(
                    $post,
                    $state,
                    $publicGroups,
                    'failed',
                    $exception->getMessage(),
                );
            } catch (Throwable) {
                // Preserve the original external error for the caller. The
                // next read still exposes the last durable checkpoint.
            }

            throw $exception;
        }

        return $post->fresh(['attachments.mediaAsset']) ?? $post;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rescheduleState(Post $post): ?array
    {
        $progress = $post->publish_progress;
        $state = is_array($progress) ? ($progress['reschedule'] ?? null) : null;

        return is_array($state) ? $state : null;
    }

    /**
     * @param  array<string, mixed>|null  $existing
     * @return list<array{index: int, post_id: string, platforms: list<string>, language: string, payload: array<string, mixed>, scheduled_at: string|null}>
     */
    private function sourceGroups(Post $post, ?array $existing): array
    {
        if ($existing !== null && is_array($existing['groups'] ?? null)) {
            return $this->normalizeGroups($existing['groups']);
        }

        $progress = $post->publish_progress;
        $completed = is_array($progress) ? ($progress['completed_groups'] ?? null) : null;
        $supplemental = is_array($progress) ? ($progress['supplemental_groups'] ?? []) : [];
        $publicGroups = $this->publicGroups($post);

        if (! is_array($completed) || $completed === [] || $supplemental !== []) {
            throw new PostsyncerException(
                'This post does not have a complete, reschedulable PostSyncer operation.',
            );
        }

        $publicById = [];
        foreach ($publicGroups as $public) {
            $publicById[(string) ($public['post_id'] ?? '')] = $public;
        }

        $groups = [];
        foreach ($completed as $completedGroup) {
            if (! is_array($completedGroup)
                || ! is_array($completedGroup['expected_payload'] ?? null)
                || ! is_int($completedGroup['index'] ?? null)
                || ! is_array($completedGroup['platforms'] ?? null)
                || ! is_string($completedGroup['language'] ?? null)) {
                throw new PostsyncerException('This post is missing the payload needed for rescheduling.');
            }

            $postId = (string) ($completedGroup['post_id'] ?? '');
            if ($postId === '' || ! isset($publicById[$postId])) {
                throw new PostsyncerException('This post has incomplete PostSyncer group metadata.');
            }

            $payload = $completedGroup['expected_payload'];
            $this->payloadWhen($payload);
            $groups[] = [
                'index' => $completedGroup['index'],
                'post_id' => $postId,
                'platforms' => array_values(array_map('strval', $completedGroup['platforms'])),
                'language' => $completedGroup['language'],
                'payload' => $payload,
                'scheduled_at' => isset($completedGroup['scheduled_at'])
                    ? (string) $completedGroup['scheduled_at']
                    : null,
            ];
        }

        if (count($groups) !== count($publicGroups)) {
            throw new PostsyncerException('This post has incomplete PostSyncer group metadata.');
        }

        usort($groups, fn (array $left, array $right): int => $left['index'] <=> $right['index']);

        return $groups;
    }

    /**
     * @param  array<int, mixed>  $groups
     * @return list<array{index: int, post_id: string, platforms: list<string>, language: string, payload: array<string, mixed>, scheduled_at: string|null}>
     */
    private function normalizeGroups(array $groups): array
    {
        $normalized = [];
        $seen = [];

        foreach ($groups as $group) {
            if (! is_array($group)
                || ! is_int($group['index'] ?? null)
                || ! is_string($group['post_id'] ?? null)
                || $group['post_id'] === ''
                || ! is_array($group['platforms'] ?? null)
                || ! is_string($group['language'] ?? null)
                || ! is_array($group['payload'] ?? null)) {
                throw new PostsyncerException('The reschedule checkpoint is invalid.');
            }

            if (isset($seen[$group['index']])) {
                throw new PostsyncerException('The reschedule checkpoint contains duplicate groups.');
            }

            $this->payloadWhen($group['payload']);
            $seen[$group['index']] = true;
            $normalized[] = [
                'index' => $group['index'],
                'post_id' => $group['post_id'],
                'platforms' => array_values(array_map('strval', $group['platforms'])),
                'language' => $group['language'],
                'payload' => $group['payload'],
                'scheduled_at' => isset($group['scheduled_at'])
                    ? (string) $group['scheduled_at']
                    : null,
            ];
        }

        if ($normalized === []) {
            throw new PostsyncerException('The reschedule checkpoint has no groups.');
        }

        usort($normalized, fn (array $left, array $right): int => $left['index'] <=> $right['index']);

        return $normalized;
    }

    /**
     * @param  list<array{index: int, post_id: string, platforms: list<string>, language: string, payload: array<string, mixed>, scheduled_at: string|null}>  $groups
     */
    private function commonSchedule(array $groups): CarbonImmutable
    {
        $first = $this->payloadWhen($groups[0]['payload']);

        foreach (array_slice($groups, 1) as $group) {
            $when = $this->payloadWhen($group['payload']);
            if (! $this->sameMinute($first, $when)
                || $first->timezoneName !== $when->timezoneName) {
                throw new PostsyncerException('PostSyncer groups do not share one schedule.');
            }
        }

        return $first;
    }

    /**
     * @param  list<array{index: int, post_id: string, platforms: list<string>, language: string, payload: array<string, mixed>, scheduled_at: string|null}>  $groups
     * @return array<string, mixed>
     */
    private function newState(array $groups, CarbonImmutable $target, CarbonImmutable $source): array
    {
        return [
            'version' => 1,
            'state' => 'running',
            'operation_id' => (string) Str::uuid(),
            'source_when' => $source->toIso8601String(),
            'target_when' => $target->toIso8601String(),
            'completed_indices' => [],
            'current_index' => null,
            'groups' => $groups,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadWhen(array $payload): CarbonImmutable
    {
        if (($payload['schedule_type'] ?? null) !== 'schedule'
            || ! is_array($payload['schedule_for'] ?? null)) {
            throw new PostsyncerException('Only scheduled PostSyncer groups can be rescheduled.');
        }

        $schedule = $payload['schedule_for'];
        $date = $schedule['date'] ?? null;
        $time = $schedule['time'] ?? null;
        $timezone = $schedule['timezone'] ?? null;

        if (! is_string($date) || ! is_string($time) || ! is_string($timezone)
            || trim($date) === '' || trim($time) === '' || trim($timezone) === '') {
            throw new PostsyncerException('The PostSyncer group has no valid schedule payload.');
        }

        try {
            return CarbonImmutable::parse($date.' '.$time, $timezone)->setSecond(0);
        } catch (Throwable $exception) {
            throw new PostsyncerException(
                'The PostSyncer group has an invalid schedule payload.',
                0,
                $exception,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withSchedule(array $payload, CarbonImmutable $when): array
    {
        $payload['schedule_type'] = 'schedule';
        $payload['schedule_for'] = [
            'date' => $when->format('Y-m-d'),
            'time' => $when->format('H:i'),
            'timezone' => $when->timezoneName,
        ];

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $platforms
     * @return array<string, mixed>|null
     */
    private function verifyAtTarget(
        array $response,
        array $payload,
        array $platforms,
        CarbonImmutable $target,
        string $postId,
    ): ?array {
        try {
            return $this->verifier->assertMatches($response, $payload, $platforms, $target, $postId);
        } catch (PostsyncerException) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $platforms
     * @return array<string, mixed>|null
     */
    private function verifyAfterUnknownUpdate(
        PostsyncerClient $client,
        array $payload,
        array $platforms,
        CarbonImmutable $target,
        string $postId,
    ): ?array {
        try {
            return $this->verifyAtTarget(
                $client->getPostWithAccountDetails($postId),
                $payload,
                $platforms,
                $target,
                $postId,
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function publicGroups(Post $post): array
    {
        $groups = $post->postsyncer['groups'] ?? null;

        if (! is_array($groups) || $groups === []) {
            throw new PostsyncerException('This post has no PostSyncer groups to reschedule.');
        }

        return array_values(array_filter($groups, 'is_array'));
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @param  array<string, mixed>  $remote
     * @return list<array<string, mixed>>
     */
    private function replacePublicGroup(array $groups, string $postId, array $remote): array
    {
        foreach ($groups as $index => $group) {
            if ((string) ($group['post_id'] ?? '') !== $postId) {
                continue;
            }

            $groups[$index]['status'] = strtoupper((string) ($remote['status'] ?? ''));
            $groups[$index]['scheduled_at'] = (string) ($remote['scheduled_at'] ?? '');

            return $groups;
        }

        throw new PostsyncerException('The PostSyncer group is missing from Content Machine.');
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  list<array<string, mixed>>  $publicGroups
     */
    private function start(Post $post, array $state, array $publicGroups): void
    {
        DB::transaction(function () use ($post, $state, $publicGroups): void {
            $locked = Post::query()->whereKey($post->getKey())->lockForUpdate()->firstOrFail();
            $progress = $locked->publish_progress;
            $existing = is_array($progress) ? ($progress['reschedule'] ?? null) : null;

            if (is_array($existing)
                && ($existing['operation_id'] ?? null) !== ($state['operation_id'] ?? null)
                && in_array($existing['state'] ?? null, ['running', 'failed'], true)) {
                throw new PostsyncerException('Another reschedule is already in progress.');
            }

            if (! is_array($progress)) {
                throw new PostsyncerException('This post has no publish progress to reschedule.');
            }

            $progress['reschedule'] = $state;
            $postsyncer = is_array($locked->postsyncer) ? $locked->postsyncer : [];
            $postsyncer['groups'] = $publicGroups;

            $locked->forceFill([
                'publish_state' => 'running',
                'publish_error' => null,
                'publish_progress' => $progress,
                'postsyncer' => $postsyncer,
                'publish_claimed_at' => null,
                'publish_lease_id' => null,
            ])->save();
        });

        $post->refresh();
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  list<array<string, mixed>>  $publicGroups
     */
    private function checkpoint(
        Post $post,
        array $state,
        array $publicGroups,
        string $publishState,
        ?string $error,
    ): void {
        DB::transaction(function () use ($post, $state, $publicGroups, $publishState, $error): void {
            $locked = Post::query()->whereKey($post->getKey())->lockForUpdate()->firstOrFail();
            $progress = $locked->publish_progress;
            $existing = is_array($progress) ? ($progress['reschedule'] ?? null) : null;

            if (! is_array($progress)
                || ! is_array($existing)
                || ($existing['operation_id'] ?? null) !== ($state['operation_id'] ?? null)) {
                throw new PostsyncerException('The reschedule checkpoint changed while it was running.');
            }

            $progress['reschedule'] = $state;
            $postsyncer = is_array($locked->postsyncer) ? $locked->postsyncer : [];
            $postsyncer['groups'] = $publicGroups;

            $locked->forceFill([
                'publish_state' => $publishState,
                'publish_error' => $error,
                'publish_progress' => $progress,
                'postsyncer' => $postsyncer,
                'publish_claimed_at' => null,
                'publish_lease_id' => null,
            ])->save();
        });

        $post->refresh();
    }

    private function parseStoredWhen(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->setSecond(0);
        } catch (Throwable) {
            return null;
        }
    }

    private function sameMinute(CarbonImmutable $left, CarbonImmutable $right): bool
    {
        return $left->setTimezone($right->timezone)->format('Y-m-d H:i')
            === $right->format('Y-m-d H:i');
    }
}
