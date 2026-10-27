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
    private const LEASE_MINUTES = 15;

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

        $completed = $this->completedGroups($video, $progress);

        // Every group is checked against its own last verified payload, so a
        // new target can replace an unfinished one. A group whose remote time
        // matches neither is refused by the verifier before any PUT.
        $this->setMarker($video, $when, 'running', null);

        $client = new PostsyncerClient($config);

        try {
            foreach ($completed as $group) {
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

                $this->checkpointGroup($video, $postId, $targetPayload, $verified);
            }
        } catch (Throwable $exception) {
            $state = $exception instanceof PostsyncerException && $exception->outcomeUnknown
                ? 'uncertain'
                : 'failed';

            $this->setMarker($video, $when, $state, $exception->getMessage());

            throw $exception;
        }

        $this->finish($video);

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
     * Write one verified group back under a row lock. Reads the latest row, so
     * a concurrent change to another group is never overwritten.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $remote
     */
    private function checkpointGroup(Video $video, string $postId, array $payload, array $remote): void
    {
        $scheduledAt = (string) ($remote['scheduled_at'] ?? '');
        $status = strtoupper((string) ($remote['status'] ?? ''));

        DB::transaction(function () use ($video, $postId, $payload, $scheduledAt, $status): void {
            $locked = Video::query()->whereKey($video->getKey())->lockForUpdate()->firstOrFail();
            $progress = $locked->publish_progress;
            $postsyncer = is_array($locked->postsyncer) ? $locked->postsyncer : [];

            if (! is_array($progress) || ! is_array($postsyncer['groups'] ?? null)) {
                throw new PostsyncerException('The video publish progress changed while rescheduling.');
            }

            $inProgress = 0;
            foreach ($progress['completed_groups'] ?? [] as $i => $group) {
                if (is_array($group) && (string) ($group['post_id'] ?? '') === $postId) {
                    $progress['completed_groups'][$i]['expected_payload'] = $payload;
                    $progress['completed_groups'][$i]['scheduled_at'] = $scheduledAt;
                    $inProgress++;
                }
            }

            $inPublic = 0;
            foreach ($postsyncer['groups'] as $i => $group) {
                if (is_array($group) && (string) ($group['post_id'] ?? '') === $postId) {
                    $postsyncer['groups'][$i]['status'] = $status;
                    $postsyncer['groups'][$i]['scheduled_at'] = $scheduledAt;
                    $inPublic++;
                }
            }

            if ($inProgress !== 1 || $inPublic !== 1) {
                throw new PostsyncerException('The PostSyncer group is missing from Content Machine.');
            }

            $locked->forceFill([
                'publish_progress' => $progress,
                'postsyncer' => $postsyncer,
            ])->save();
        });

        $video->refresh();
    }

    private function finish(Video $video): void
    {
        DB::transaction(function () use ($video): void {
            $locked = Video::query()->whereKey($video->getKey())->lockForUpdate()->firstOrFail();
            $progress = $locked->publish_progress;

            if (is_array($progress)) {
                unset($progress['reschedule']);
                $locked->forceFill(['publish_progress' => $progress])->save();
            }
        });

        $video->refresh();
    }

    /**
     * Records the reschedule marker. A running marker is a lease: a second
     * request is refused until it expires, so two calls cannot interleave PUTs.
     */
    private function setMarker(Video $video, CarbonImmutable $when, string $state, ?string $error): void
    {
        DB::transaction(function () use ($video, $when, $state, $error): void {
            $locked = Video::query()->whereKey($video->getKey())->lockForUpdate()->firstOrFail();
            $progress = $locked->publish_progress;

            if (! is_array($progress)) {
                throw new PostsyncerException('The video publish progress changed while rescheduling.');
            }

            $existing = $progress['reschedule'] ?? null;
            if ($state === 'running'
                && is_array($existing)
                && ($existing['state'] ?? null) === 'running'
                && CarbonImmutable::parse($existing['started_at'])->addMinutes(self::LEASE_MINUTES)->isFuture()) {
                throw new PostsyncerException('Another reschedule is already running for this video.');
            }

            $progress['reschedule'] = [
                'target_when' => $when->toIso8601String(),
                'state' => $state,
                'started_at' => CarbonImmutable::now()->toIso8601String(),
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
