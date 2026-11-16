<?php

namespace App\Actions\Socialreins;

use App\Actions\Postsyncer\SyncScheduledPostsAction;
use App\Data\Socialreins\MoveOffPostsyncerResult;
use App\Jobs\PublishSocialreinsJob;
use App\Models\Post;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Socialreins\SocialreinsConfig;
use App\Support\Socialreins\SocialreinsResults;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Moves one scheduled post or video off PostSyncer (socialreins:move-off-postsyncer), in this order:
 *
 * 1. The plan (time, pairs to move, pairs left out) is stored on the item as
 *    `publish_progress.move_off_postsyncer`, so an interrupted move is found and finished later.
 * 2. Its PostSyncer posts are deleted (TakeDownPostsyncerGroupsAction: only while still scheduled or
 *    a draft, 404 counts as gone). Any problem stops the item there: if nothing was deleted the plan
 *    is removed again and nothing changed; otherwise the item is marked failed naming what is now
 *    scheduled nowhere.
 * 3. Only then the pairs SocialReins covers go to it at the same time, with the same captions and
 *    media (the planners read the item as it is). PostSyncer is never asked to publish, and
 *    SocialReins is only asked once every PostSyncer post is gone, so nothing goes out twice.
 *    A hand-over that failed is sent again on the next run; the SocialReins publish is idempotent.
 */
class MoveOffPostsyncerAction
{
    public const MARKER = 'move_off_postsyncer';

    public const LEAD_MINUTES = 15;

    public function __construct(
        private readonly TakeDownPostsyncerGroupsAction $takeDown,
    ) {}

    /** Null when the item has nothing on PostSyncer to move. */
    public function handle(Post|Video $subject, bool $apply, int $leadMinutes = self::LEAD_MINUTES): ?MoveOffPostsyncerResult
    {
        $subject->loadMissing('workspace');
        $workspace = $subject->workspace;
        $marker = $subject->publish_progress[self::MARKER] ?? null;
        $handedOver = is_array($marker) && ($marker['stage'] ?? null) === 'handed_over';

        if (is_array($marker) ? ($handedOver && $subject->publish_state !== 'failed') : ! $this->onPostsyncer($subject)) {
            return null;
        }

        $plan = is_array($marker) ? $marker : $this->plan($subject, $workspace);
        $report = fn (string $outcome): MoveOffPostsyncerResult => new MoveOffPostsyncerResult(
            $subject->human_id,
            $plan['when'] ?? null,
            $plan['postsyncer'] ?? [],
            $plan['move'] ?? [],
            $plan['left_out'] ?? [],
            $outcome,
        );

        if (is_string($plan['skip'] ?? null)) {
            return $report('skipped: '.$plan['skip']);
        }
        if (CarbonImmutable::parse($plan['when'])->isBefore(now()->addMinutes($leadMinutes))) {
            return $report("skipped: due within {$leadMinutes} minutes, too close to move");
        }
        if (in_array($subject->publish_state, ['queued', 'running'], true)) {
            return $report('skipped: a publish is in flight');
        }
        if (! $apply) {
            return $report(match (true) {
                $handedOver => 'would send the failed SocialReins hand-over again',
                is_array($marker) => 'would finish the interrupted move',
                default => 'would move',
            });
        }
        if (SocialreinsConfig::postsyncerEnabled($workspace)) {
            return $report('skipped: PostSyncer is still on for this workspace (Settings > SocialReins)');
        }
        if (! SocialreinsConfig::fromWorkspace($workspace)->isReadyForPublish()) {
            return $report('skipped: SocialReins is not configured for publishing');
        }

        if (! $handedOver) {
            if (! is_array($marker)) {
                $this->writeMarker($subject, [...$plan, 'stage' => 'deleting']);
            }

            $before = $this->deletedPostIds($subject->fresh() ?? $subject);
            $problems = $this->takeDown->handle($subject->fresh() ?? $subject);

            if ($problems !== []) {
                if (! is_array($marker) && $this->deletedPostIds($subject->fresh() ?? $subject) === $before) {
                    $this->writeMarker($subject, null);

                    return $report('failed: '.implode(' ', $problems).' Nothing was changed.');
                }

                return $report('failed: '.$this->recordFailure($subject, $plan, 'Moving off PostSyncer stopped: '.implode(' ', $problems)));
            }
        }

        try {
            return $report($this->handOver($subject, $plan));
        } catch (Throwable $exception) {
            report($exception);

            return $report('failed: '.$this->recordFailure($subject, $plan, 'Its PostSyncer posts are deleted, but the hand-over to SocialReins failed: '.$exception->getMessage()));
        }
    }

    /** Scheduled, with a PostSyncer post not yet deleted or a PostSyncer run (progress without a driver). */
    private function onPostsyncer(Post|Video $subject): bool
    {
        if ($subject->status !== 'scheduled') {
            return false;
        }

        return $this->liveGroups($subject) !== []
            || (is_array($subject->publish_progress) && ! isset($subject->publish_progress['driver']));
    }

    /** @return list<array<string, mixed>> */
    private function liveGroups(Post|Video $subject): array
    {
        return array_values(array_filter(
            (array) ($subject->postsyncer['groups'] ?? []),
            fn (mixed $group): bool => is_array($group) && filled($group['post_id'] ?? null) && ($group['status'] ?? null) !== 'DELETED',
        ));
    }

    /** @return array{when?: string, postsyncer: list<string>, move: list<string>, left_out: list<string>, skip?: string} */
    private function plan(Post|Video $subject, Workspace $workspace): array
    {
        $config = SocialreinsConfig::fromWorkspace($workspace);
        $timezone = trim((string) $workspace->timezone) !== '' ? (string) $workspace->timezone : 'Asia/Dhaka';
        $plan = ['postsyncer' => [], 'move' => [], 'left_out' => []];
        $times = [];
        $published = false;

        foreach ($this->liveGroups($subject) as $group) {
            foreach ((array) ($group['platforms'] ?? []) as $platform) {
                $pair = $group['language'].':'.$platform;
                $plan['postsyncer'][] = $pair;
                $plan[$config->covers((string) $group['language'], (string) $platform) ? 'move' : 'left_out'][] = $pair;
            }
            $at = $group['scheduled_at'] ?? null;
            $times[] = is_string($at) && $at !== '' ? CarbonImmutable::parse($at, $timezone)->utc()->toIso8601String() : null;
            // The take down reads each post again and deletes it only while scheduled or a draft.
            $published = $published || in_array(strtoupper((string) ($group['status'] ?? '')), SyncScheduledPostsAction::TERMINAL_GROUP_STATUSES, true);
        }

        $times = array_values(array_unique($times));
        $skip = match (true) {
            $plan['postsyncer'] === [] => 'a PostSyncer publish with no PostSyncer post, check it by hand',
            $published => 'already published (or failed) on PostSyncer',
            in_array(null, $times, true) => 'a PostSyncer post has no scheduled time',
            count($times) > 1 => 'its PostSyncer posts have different times, move it by hand',
            default => null,
        };

        return $skip !== null ? [...$plan, 'skip' => $skip] : [...$plan, 'when' => (string) $times[0]];
    }

    /** @param  array<string, mixed>|null  $marker  null removes it */
    private function writeMarker(Post|Video $subject, ?array $marker): void
    {
        DB::transaction(function () use ($subject, $marker): void {
            $locked = $subject::query()->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();
            $progress = is_array($locked->publish_progress) ? $locked->publish_progress : [];
            unset($progress[self::MARKER]);
            if ($marker !== null) {
                $progress[self::MARKER] = $marker;
            }
            $locked->forceFill(['publish_progress' => $progress === [] ? null : $progress])->save();
        });
    }

    /** @return list<string> */
    private function deletedPostIds(Post|Video $subject): array
    {
        return array_values(array_map(
            fn (array $group): string => (string) $group['post_id'],
            array_filter((array) ($subject->postsyncer['groups'] ?? []), fn (mixed $group): bool => is_array($group) && ($group['status'] ?? null) === 'DELETED'),
        ));
    }

    /**
     * A clear failed state, so an item whose PostSyncer posts went is never silently unpublished.
     *
     * @param  array<string, mixed>  $plan
     */
    private function recordFailure(Post|Video $subject, array $plan, string $why): string
    {
        $message = $why.' Not scheduled anywhere now: '.implode(', ', [...$plan['move'], ...$plan['left_out']])
            .'. Run socialreins:move-off-postsyncer --apply again to finish.';

        DB::transaction(function () use ($subject, $message): void {
            $subject::query()->whereKey($subject->getKey())->lockForUpdate()->firstOrFail()
                ->forceFill(['publish_state' => 'failed', 'publish_error' => $message])->save();
        });

        return $message;
    }

    /** @param  array<string, mixed>  $plan */
    private function handOver(Post|Video $subject, array $plan): string
    {
        return DB::transaction(function () use ($subject, $plan): string {
            $locked = $subject::query()->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();
            $progress = is_array($locked->publish_progress) ? $locked->publish_progress : [];
            $onSocialreins = ($progress['driver'] ?? null) === SocialreinsConfig::DRIVER && SocialreinsResults::rowsOf($locked) !== [];

            // Kept for audit only, as a take down does, so nothing reads them as scheduled.
            $postsyncer = is_array($locked->postsyncer) ? $locked->postsyncer : [];
            $postsyncer = ['groups' => [], 'cleared_groups' => [...($postsyncer['cleared_groups'] ?? []), ...($postsyncer['groups'] ?? [])]] + $postsyncer;

            $leftOut = array_values(array_unique([...($onSocialreins ? (array) ($progress['left_out'] ?? []) : []), ...$plan['left_out']]));
            $platforms = array_values(array_unique(array_map(fn (string $pair): string => explode(':', $pair, 2)[1], $plan['move'])));
            $options = ['when' => $plan['when'], 'platforms' => $platforms, 'confirm_ask' => true];
            $base = [
                // A split's SocialReins half stays as it is; the split itself is over.
                ...($onSocialreins ? array_diff_key($progress, ['split' => true, 'reschedule' => true]) : []),
                'driver' => SocialreinsConfig::DRIVER,
                ...($leftOut !== [] ? ['left_out' => $leftOut] : []),
                self::MARKER => [...$plan, 'stage' => 'handed_over'],
            ];

            if ($platforms === [] && $onSocialreins) {
                $locked->forceFill(['postsyncer' => $postsyncer, 'publish_progress' => $base])->save();

                return 'moved: nothing SocialReins covers, the rest was already on SocialReins';
            }

            if ($platforms === []) {
                $message = 'Nothing left to publish: SocialReins does not cover '.implode(', ', $leftOut).', and PostSyncer is off.';
                $locked->forceFill([
                    'postsyncer' => $postsyncer,
                    'status' => $locked instanceof Video ? 'recorded' : 'ready',
                    'publish_state' => 'failed',
                    'publish_error' => $message,
                    'publish_progress' => [...$base, 'state' => 'failed', 'options' => []],
                ])->save();

                return 'left out: '.$message;
            }

            $locked->forceFill([
                'postsyncer' => $postsyncer,
                'publish_state' => 'queued',
                'publish_error' => null,
                // Same shape EnqueueSocialreinsPublishAction writes.
                'publish_progress' => [...$base, 'state' => 'failed', 'options' => $options],
                ...($locked instanceof Post ? ['publish_claimed_at' => null, 'publish_lease_id' => null] : []),
            ])->save();

            // The database queue shares this connection: the job commits with the row.
            dispatch(new PublishSocialreinsJob($locked, $options))->beforeCommit();

            return 'moved: handed to SocialReins';
        });
    }
}
