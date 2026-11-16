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
 * 1. Claim: the plan (time; per PostSyncer post the pairs to move and to leave out) is stored as
 *    `publish_progress.move_off_postsyncer` with stage `moving`, under the row lock and only if no
 *    other run stored one. From here the item page says it is being moved, and a later run
 *    resumes it whatever its time (SocialReins sends a past time at once).
 * 2. Its PostSyncer posts are deleted (TakeDownPostsyncerGroupsAction). If nothing was deleted and
 *    something failed, the claim is removed again and nothing changed.
 * 3. Hand-over, under the row lock: the covered pairs of each deleted post go to SocialReins at the
 *    same time, through one PublishSocialreinsJob. A pair whose platform is still on PostSyncer
 *    in any language waits (the planners pick platforms for every language at once), so nothing
 *    goes out twice. What is still on PostSyncer is named on the item; the next run continues.
 *    Stage `done` once nothing is left on PostSyncer. A failed hand-over is sent again by the next
 *    run; the SocialReins publish is idempotent, and a run that finds another run's hand-over in
 *    place does nothing.
 *
 * A PostSyncer run that never created a post (SocialreinsConfig::runCreatedNothing()) has nothing
 * to delete: its covered platforms move as they are.
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
        $marker = is_array($marker) ? $marker : null;
        $done = ($marker['stage'] ?? null) === 'done';

        if ($marker !== null ? ($done && $subject->publish_state !== 'failed') : ! $this->onPostsyncer($subject)) {
            return null;
        }

        $plan = $marker ?? $this->plan($subject, $workspace);
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
        // Only a move not started yet: one part-way through always finishes.
        if ($marker === null && CarbonImmutable::parse($plan['when'])->isBefore(now()->addMinutes($leadMinutes))) {
            return $report("skipped: due within {$leadMinutes} minutes, too close to move");
        }
        if (in_array($subject->publish_state, ['queued', 'running'], true)) {
            return $report('skipped: a publish is in flight');
        }
        if (! $apply) {
            return $report(match (true) {
                $done => 'would send the failed SocialReins hand-over again',
                $marker !== null => 'would finish the interrupted move',
                default => 'would move',
            });
        }
        if (SocialreinsConfig::postsyncerEnabled($workspace)) {
            return $report('skipped: PostSyncer is still on for this workspace (Settings > SocialReins)');
        }
        if (! SocialreinsConfig::fromWorkspace($workspace)->isReadyForPublish()) {
            return $report('skipped: SocialReins is not configured for publishing');
        }
        if ($marker === null && ! $this->claim($subject, $plan)) {
            return $report('skipped: another run is moving it');
        }

        $problems = $done ? [] : $this->takeDown->handle($subject->fresh() ?? $subject);

        if ($problems !== [] && $marker === null && $this->gonePairs($subject->fresh() ?? $subject, $plan) === null) {
            $this->unclaim($subject);

            return $report('failed: '.implode(' ', $problems).' Nothing was changed.');
        }

        try {
            return $report($this->handOver($subject, $problems));
        } catch (Throwable $exception) {
            report($exception);

            return $report('failed: '.$this->recordFailure($subject, 'The hand-over to SocialReins failed: '.$exception->getMessage()));
        }
    }

    /** Scheduled, with a PostSyncer post not yet deleted or a PostSyncer run (progress without a driver). */
    private function onPostsyncer(Post|Video $subject): bool
    {
        return $subject->status === 'scheduled'
            && ($this->liveGroups($subject) !== []
                || (is_array($subject->publish_progress) && ! isset($subject->publish_progress['driver'])));
    }

    /** @return list<array<string, mixed>> */
    private function liveGroups(Post|Video $subject): array
    {
        return array_values(array_filter(
            (array) ($subject->postsyncer['groups'] ?? []),
            fn (mixed $group): bool => is_array($group) && filled($group['post_id'] ?? null) && ($group['status'] ?? null) !== 'DELETED',
        ));
    }

    /**
     * @return array<string, mixed> `when`, per-post `groups` ({post_id, move, left_out}; post_id null for a
     *                              run that created none), the flat lists for the report, or `skip`
     */
    private function plan(Post|Video $subject, Workspace $workspace): array
    {
        $config = SocialreinsConfig::fromWorkspace($workspace);
        $timezone = trim((string) $workspace->timezone) !== '' ? (string) $workspace->timezone : 'Asia/Dhaka';
        $at = fn (mixed $value): ?string => is_string($value) && $value !== '' ? CarbonImmutable::parse($value, $timezone)->utc()->toIso8601String() : null;
        $groups = [];
        $times = [];
        $published = false;
        $progress = is_array($subject->publish_progress) ? $subject->publish_progress : [];

        foreach ($this->liveGroups($subject) as $group) {
            $entry = ['post_id' => (string) $group['post_id'], 'postsyncer' => [], 'move' => [], 'left_out' => []];
            foreach ((array) ($group['platforms'] ?? []) as $platform) {
                $pair = $group['language'].':'.$platform;
                $entry['postsyncer'][] = $pair;
                $entry[$config->covers((string) $group['language'], (string) $platform, strict: true) ? 'move' : 'left_out'][] = $pair;
            }
            $groups[] = $entry;
            $times[] = $at($group['scheduled_at'] ?? null);
            // The take down reads each post again and deletes it only while scheduled or a draft.
            $published = $published || in_array(strtoupper((string) ($group['status'] ?? '')), SyncScheduledPostsAction::TERMINAL_GROUP_STATUSES, true);
        }

        $unsafeRun = false;
        if ($groups === [] && ! isset($progress['driver'])) {
            // The run never got a post id; move its platforms only if it provably created nothing.
            $unsafeRun = ! SocialreinsConfig::runCreatedNothing($progress);
            $options = is_array($progress['options'] ?? null) ? $progress['options'] : [];
            $coverage = SocialreinsConfig::coverage($subject, $workspace, array_intersect_key($options, ['platforms' => true]));
            $groups[] = ['post_id' => null, 'postsyncer' => [], 'move' => $coverage['covered'], 'left_out' => $coverage['left_out']];
            $times[] = $at($options['when'] ?? null);
        }

        $times = array_values(array_unique($times));
        $plan = ['groups' => $groups, 'postsyncer' => [], 'move' => [], 'left_out' => []];
        foreach ($groups as $group) {
            array_push($plan['postsyncer'], ...$group['postsyncer']);
            array_push($plan['move'], ...$group['move']);
            array_push($plan['left_out'], ...$group['left_out']);
        }
        $skip = match (true) {
            $unsafeRun => 'a PostSyncer create may have gone out without a post id, check PostSyncer by hand',
            $plan['move'] === [] && $plan['left_out'] === [] => 'nothing to move',
            $published => 'already published (or failed) on PostSyncer',
            in_array(null, $times, true) => 'no scheduled time',
            count($times) > 1 => 'its PostSyncer posts have different times, move it by hand',
            default => null,
        };

        return $skip !== null ? [...$plan, 'skip' => $skip] : [...$plan, 'when' => (string) $times[0]];
    }

    /**
     * Stores the plan unless another run already did.
     *
     * @param  array<string, mixed>  $plan
     */
    private function claim(Post|Video $subject, array $plan): bool
    {
        return DB::transaction(function () use ($subject, $plan): bool {
            $locked = $subject::query()->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();
            $progress = is_array($locked->publish_progress) ? $locked->publish_progress : [];
            if (isset($progress[self::MARKER])) {
                return false;
            }

            $progress[self::MARKER] = [...$plan, 'stage' => 'moving', 'handed' => [], 'note' => 'Being moved off PostSyncer: not finished yet.'];
            $locked->forceFill(['publish_progress' => $progress])->save();

            return true;
        });
    }

    private function unclaim(Post|Video $subject): void
    {
        DB::transaction(function () use ($subject): void {
            $locked = $subject::query()->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();
            $progress = is_array($locked->publish_progress) ? $locked->publish_progress : [];
            unset($progress[self::MARKER]);
            $locked->forceFill(['publish_progress' => $progress === [] ? null : $progress])->save();
        });
    }

    /**
     * The pairs of the plan's PostSyncer posts that are gone (deleted, or never created), and those
     * still on PostSyncer. Null when nothing is gone.
     *
     * @param  array<string, mixed>  $plan
     * @return array{move: list<string>, left_out: list<string>, still: list<string>}|null
     */
    private function gonePairs(Post|Video $subject, array $plan): ?array
    {
        $deleted = [];
        // A hand-over moves deleted posts to cleared_groups.
        foreach ([...(array) ($subject->postsyncer['groups'] ?? []), ...(array) ($subject->postsyncer['cleared_groups'] ?? [])] as $group) {
            if (is_array($group) && ($group['status'] ?? null) === 'DELETED') {
                $deleted[] = (string) ($group['post_id'] ?? '');
            }
        }

        $pairs = ['move' => [], 'left_out' => [], 'still' => []];
        $anyGone = false;
        foreach ($plan['groups'] as $group) {
            if ($group['post_id'] === null || in_array($group['post_id'], $deleted, true)) {
                $anyGone = true;
                array_push($pairs['move'], ...$group['move']);
                array_push($pairs['left_out'], ...$group['left_out']);
            } else {
                array_push($pairs['still'], ...$group['move'], ...$group['left_out']);
            }
        }

        return $anyGone ? $pairs : null;
    }

    /** A clear failed state, so an item whose PostSyncer posts went is never silently unpublished. */
    private function recordFailure(Post|Video $subject, string $why): string
    {
        $message = $why.' Run socialreins:move-off-postsyncer --apply again to finish.';

        DB::transaction(function () use ($subject, $message): void {
            $locked = $subject::query()->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();
            $progress = is_array($locked->publish_progress) ? $locked->publish_progress : [];
            if (is_array($progress[self::MARKER] ?? null)) {
                $progress[self::MARKER]['note'] = $message;
            }
            $locked->forceFill(['publish_state' => 'failed', 'publish_error' => $message, 'publish_progress' => $progress])->save();
        });

        return $message;
    }

    /** @param  list<string>  $problems  what the take down could not delete */
    private function handOver(Post|Video $subject, array $problems): string
    {
        return DB::transaction(function () use ($subject, $problems): string {
            $locked = $subject::query()->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();
            $progress = is_array($locked->publish_progress) ? $locked->publish_progress : [];
            $marker = $progress[self::MARKER] ?? null;

            // Re-read under the lock: another run may have handed it over meanwhile.
            if (! is_array($marker) || in_array($locked->publish_state, ['queued', 'running'], true)) {
                return 'skipped: another run already handed it over';
            }
            $retry = ($marker['stage'] ?? null) === 'done';
            if ($retry && $locked->publish_state !== 'failed') {
                return 'skipped: another run already handed it over';
            }

            $gone = $this->gonePairs($locked, $marker) ?? ['move' => [], 'left_out' => [], 'still' => [...$marker['move'], ...$marker['left_out']]];
            $onSocialreins = ($progress['driver'] ?? null) === SocialreinsConfig::DRIVER;
            // A SocialReins hand-over of this move that failed is sent again (idempotent).
            $resend = $locked->publish_state === 'failed' && $onSocialreins && ($marker['handed'] ?? []) !== [];
            $stillPlatforms = array_map(fn (string $pair): string => explode(':', $pair, 2)[1], $gone['still']);
            $handed = (array) ($marker['handed'] ?? []);
            // A platform still on PostSyncer in any language waits: the job plans platforms for every language.
            $new = array_values(array_filter(
                array_diff($gone['move'], $handed),
                fn (string $pair): bool => ! in_array(explode(':', $pair, 2)[1], $stillPlatforms, true),
            ));
            $handed = array_values(array_unique([...$handed, ...$new]));
            $waiting = array_values(array_diff($gone['move'], $handed));
            $notMoved = [...$gone['still'], ...$waiting];

            $marker['handed'] = $handed;
            $marker['stage'] = $notMoved === [] ? 'done' : 'moving';
            $marker['note'] = $notMoved === [] ? null : ($problems !== [] ? 'Moving off PostSyncer stopped: '.implode(' ', $problems) : 'Moving off PostSyncer is not finished.')
                .' Not moved yet: '.implode(', ', $notMoved).'. Run socialreins:move-off-postsyncer --apply again to finish.';

            // Deleted posts are kept for audit only, as a take down does; the rest stays.
            $postsyncer = is_array($locked->postsyncer) ? $locked->postsyncer : [];
            $groups = (array) ($postsyncer['groups'] ?? []);
            $deleted = array_values(array_filter($groups, fn (mixed $g): bool => is_array($g) && ($g['status'] ?? null) === 'DELETED'));
            $postsyncer = [
                'groups' => array_values(array_filter($groups, fn (mixed $g): bool => ! in_array($g, $deleted, true))),
                'cleared_groups' => [...($postsyncer['cleared_groups'] ?? []), ...$deleted],
            ] + $postsyncer;

            $platforms = array_values(array_unique(array_map(fn (string $pair): string => explode(':', $pair, 2)[1], $resend ? $handed : $new)));
            $leftOut = array_values(array_unique([...($onSocialreins ? (array) ($progress['left_out'] ?? []) : []), ...$gone['left_out']]));

            if ($platforms === []) {
                $progress[self::MARKER] = $marker;
                if ($notMoved !== []) {
                    // Nothing new to send yet: the item keeps its driver and says what is not moved. A
                    // SocialReins part already handed over keeps its own state.
                    $locked->forceFill([
                        'postsyncer' => $postsyncer,
                        'publish_progress' => $progress,
                        ...($onSocialreins ? [] : ['publish_state' => 'failed', 'publish_error' => $marker['note']]),
                    ])->save();

                    return 'failed: '.$marker['note'];
                }
                if ($onSocialreins && SocialreinsResults::rowsOf($locked) !== []) {
                    $locked->forceFill(['postsyncer' => $postsyncer, 'publish_progress' => [...$progress, ...($leftOut !== [] ? ['left_out' => $leftOut] : [])]])->save();

                    return 'moved: nothing SocialReins covers, the rest was already on SocialReins';
                }

                $message = 'Nothing left to publish: SocialReins does not cover '.implode(', ', $leftOut).', and PostSyncer is off.';
                $locked->forceFill([
                    'postsyncer' => $postsyncer,
                    'status' => $locked instanceof Video ? 'recorded' : 'ready',
                    'publish_state' => 'failed',
                    'publish_error' => $message,
                    'publish_progress' => ['driver' => SocialreinsConfig::DRIVER, 'state' => 'failed', 'options' => [], 'left_out' => $leftOut, self::MARKER => $marker],
                ])->save();

                return 'left out: '.$message;
            }

            $options = ['when' => $marker['when'], 'platforms' => $platforms, 'confirm_ask' => true];
            $locked->forceFill([
                'postsyncer' => $postsyncer,
                'publish_state' => 'queued',
                'publish_error' => null,
                // Same shape EnqueueSocialreinsPublishAction writes. A split's SocialReins half stays
                // as it is; the split itself is over.
                'publish_progress' => [
                    ...($onSocialreins ? array_diff_key($progress, ['split' => true, 'reschedule' => true]) : []),
                    'driver' => SocialreinsConfig::DRIVER,
                    'state' => 'failed',
                    'options' => $options,
                    ...($leftOut !== [] ? ['left_out' => $leftOut] : []),
                    self::MARKER => $marker,
                ],
                ...($locked instanceof Post ? ['publish_claimed_at' => null, 'publish_lease_id' => null] : []),
            ])->save();

            // The database queue shares this connection: the job commits with the row.
            dispatch(new PublishSocialreinsJob($locked, $options))->beforeCommit();

            return $notMoved === [] ? 'moved: handed to SocialReins' : 'partly moved: '.$marker['note'];
        });
    }
}
