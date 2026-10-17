<?php

namespace App\Actions\Postsyncer;

use App\Data\Postsyncer\ReschedulePostData;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerClient;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Postsyncer\PostsyncerException;
use App\Support\Postsyncer\PostsyncerPostVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Move every already-scheduled PostSyncer group of a video to a new time with
 * PUT, never delete-and-recreate.
 *
 * Each group is idempotent on its own: before a PUT it is checked against the
 * stored source payload, and a group already at the target is skipped. The
 * stored payload and schedule are checkpointed after every group, so a retry
 * after a failure only touches the groups still at the old time.
 */
final class VideoRescheduleAction
{
    public function __construct(
        private readonly PostsyncerPostVerifier $verifier,
    ) {}

    public function handle(Video $video, Workspace $workspace, ReschedulePostData $data): Video
    {
        abort_if($video->workspace_id !== $workspace->id, 404);

        $video->refresh();
        $when = $data->when;

        if ($video->status !== 'scheduled') {
            throw new PostsyncerException('Only scheduled videos can be rescheduled.');
        }

        if ($video->publish_state !== 'succeeded' || $video->hasUncertainPublish()) {
            throw new PostsyncerException(
                'The video has a publish in progress or an uncertain publish. Reconcile it first.',
            );
        }

        if ($when->lessThanOrEqualTo(CarbonImmutable::now())) {
            throw new PostsyncerException('The new schedule must be in the future.');
        }

        $config = PostsyncerConfig::fromWorkspace($workspace);
        if (! $config->isReadyForPublish()) {
            throw new PostsyncerException('PostSyncer is not configured for publishing.');
        }

        $progress = $video->publish_progress;
        if (! is_array($progress) || ($progress['state'] ?? null) !== 'succeeded') {
            throw new PostsyncerException('This video has no completed PostSyncer publish to reschedule.');
        }

        $existing = $progress['reschedule'] ?? null;
        if (is_array($existing) && ! CarbonImmutable::parse($existing['target_when'])->equalTo($when)) {
            throw new PostsyncerException(
                'A previous reschedule is unfinished. Retry it with its original target time first.',
            );
        }

        $completed = $this->completedGroups($video, $progress);
        $publicGroups = $video->postsyncer['groups'];

        $this->markReschedule($video, $when, 'running', null);

        $client = new PostsyncerClient($config);

        try {
            foreach ($completed as $index => $group) {
                $postId = (string) $group['post_id'];
                $source = $group['expected_payload'];
                $platforms = array_values(array_map('strval', $group['platforms']));

                $sourceWhen = $this->payloadWhen($source);
                $groupTarget = $when->setTimezone($sourceWhen->timezone);
                $targetPayload = $this->withSchedule($source, $groupTarget);

                $remote = $client->getPostWithAccountDetails($postId);
                $verified = $this->atTarget($remote, $targetPayload, $platforms, $groupTarget, $postId);

                if ($verified === null) {
                    $this->verifier->assertMatches($remote, $source, $platforms, $sourceWhen, $postId);
                    $verified = $this->putAndVerify($client, $targetPayload, $platforms, $groupTarget, $postId);
                }

                $completed[$index]['expected_payload'] = $targetPayload;
                $completed[$index]['scheduled_at'] = (string) ($verified['scheduled_at'] ?? '');
                $publicGroups = $this->replacePublicGroup($publicGroups, $postId, $verified);

                $this->checkpoint($video, $completed, $publicGroups, $when, 'running', null);
            }
        } catch (Throwable $exception) {
            $state = $exception instanceof PostsyncerException && $exception->outcomeUnknown
                ? 'uncertain'
                : 'failed';

            $this->markReschedule($video, $when, $state, $exception->getMessage());

            throw $exception;
        }

        $this->checkpoint($video, $completed, $publicGroups, $when, null, null);

        return $video->fresh() ?? $video;
    }

    /**
     * @param  array<string, mixed>  $progress
     * @return list<array<string, mixed>>
     */
    private function completedGroups(Video $video, array $progress): array
    {
        $completed = array_values(array_filter($progress['completed_groups'] ?? [], 'is_array'));
        $public = $video->postsyncer['groups'] ?? null;

        if ($completed === [] || ! is_array($public) || count($public) !== count($completed)) {
            throw new PostsyncerException('This video is missing the group metadata needed for rescheduling.');
        }

        foreach ($completed as $group) {
            if (! is_string($group['post_id'] ?? null)
                || ! is_array($group['expected_payload'] ?? null)
                || ! is_array($group['platforms'] ?? null)) {
                throw new PostsyncerException('This video is missing the payload needed for rescheduling.');
            }
        }

        usort($completed, fn (array $left, array $right): int => $left['index'] <=> $right['index']);

        return $completed;
    }

    /**
     * Fetch the group's remote state and checks it against the target. Returns
     * null when the group is not at the target yet.
     *
     * @param  array<string, mixed>  $remote
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $platforms
     * @return array<string, mixed>|null
     */
    private function atTarget(array $remote, array $payload, array $platforms, CarbonImmutable $when, string $postId): ?array
    {
        try {
            return $this->verifier->assertMatches($remote, $payload, $platforms, $when, $postId);
        } catch (PostsyncerException) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $platforms
     * @return array<string, mixed>
     */
    private function putAndVerify(
        PostsyncerClient $client,
        array $payload,
        array $platforms,
        CarbonImmutable $when,
        string $postId,
    ): array {
        try {
            $client->updatePost($postId, $payload);
        } catch (Throwable $exception) {
            if ($exception instanceof PostsyncerException && ! $exception->outcomeUnknown) {
                throw $exception;
            }

            // The PUT may have been applied even though the response was lost.
            $verified = $this->safeAtTarget($client, $payload, $platforms, $when, $postId);

            if ($verified === null) {
                throw new PostsyncerException(
                    'PostSyncer reschedule outcome is uncertain. Retry to reconcile the target schedule.',
                    0,
                    $exception,
                    true,
                    true,
                );
            }

            return $verified;
        }

        return $this->verifier->assertMatches(
            $client->getPostWithAccountDetails($postId),
            $payload,
            $platforms,
            $when,
            $postId,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $platforms
     * @return array<string, mixed>|null
     */
    private function safeAtTarget(
        PostsyncerClient $client,
        array $payload,
        array $platforms,
        CarbonImmutable $when,
        string $postId,
    ): ?array {
        try {
            return $this->atTarget($client->getPostWithAccountDetails($postId), $payload, $platforms, $when, $postId);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $publicGroups
     * @param  array<string, mixed>  $remote
     * @return list<array<string, mixed>>
     */
    private function replacePublicGroup(array $publicGroups, string $postId, array $remote): array
    {
        foreach ($publicGroups as $index => $group) {
            if ((string) ($group['post_id'] ?? '') === $postId) {
                $publicGroups[$index]['status'] = strtoupper((string) ($remote['status'] ?? ''));
                $publicGroups[$index]['scheduled_at'] = (string) ($remote['scheduled_at'] ?? '');

                return $publicGroups;
            }
        }

        throw new PostsyncerException('The PostSyncer group is missing from Content Machine.');
    }

    /**
     * @param  list<array<string, mixed>>  $completed
     * @param  list<array<string, mixed>>  $publicGroups
     */
    private function checkpoint(
        Video $video,
        array $completed,
        array $publicGroups,
        CarbonImmutable $when,
        ?string $state,
        ?string $error,
    ): void {
        DB::transaction(function () use ($video, $completed, $publicGroups, $when, $state, $error): void {
            $locked = Video::query()->whereKey($video->getKey())->lockForUpdate()->firstOrFail();
            $progress = $locked->publish_progress;

            if (! is_array($progress)) {
                throw new PostsyncerException('The video publish progress changed while rescheduling.');
            }

            $progress['completed_groups'] = $completed;
            $progress['reschedule'] = $state === null
                ? null
                : ['target_when' => $when->toIso8601String(), 'state' => $state, 'error' => $error];

            if ($progress['reschedule'] === null) {
                unset($progress['reschedule']);
            }

            $postsyncer = is_array($locked->postsyncer) ? $locked->postsyncer : [];
            $postsyncer['groups'] = $publicGroups;

            $locked->forceFill([
                'publish_progress' => $progress,
                'postsyncer' => $postsyncer,
            ])->save();
        });

        $video->refresh();
    }

    private function markReschedule(Video $video, CarbonImmutable $when, string $state, ?string $error): void
    {
        DB::transaction(function () use ($video, $when, $state, $error): void {
            $locked = Video::query()->whereKey($video->getKey())->lockForUpdate()->firstOrFail();
            $progress = $locked->publish_progress;

            if (! is_array($progress)) {
                throw new PostsyncerException('The video publish progress changed while rescheduling.');
            }

            $progress['reschedule'] = [
                'target_when' => $when->toIso8601String(),
                'state' => $state,
                'error' => $error,
            ];

            $locked->forceFill(['publish_progress' => $progress])->save();
        });

        $video->refresh();
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
            throw new PostsyncerException('The PostSyncer group has an invalid schedule payload.', 0, $exception);
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
}
