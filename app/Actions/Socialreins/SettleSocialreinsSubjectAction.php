<?php

namespace App\Actions\Socialreins;

use App\Actions\Postsyncer\SyncScheduledPostsAction;
use App\Jobs\PublishSocialreinsJob;
use App\Models\Post;
use App\Models\SocialreinsGroup;
use App\Models\TelegramPostRequest;
use App\Models\Video;
use App\Support\Socialreins\SocialreinsConfig;
use App\Support\Socialreins\SocialreinsResults;
use App\Support\Telegram\TelegramPublishRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Derives a Post's or Video's publish state from what SocialReins reported for its groups.
 * Called by the publish job when it finishes, by the webhook and by the reconcile command.
 *
 * Handed over and still publishing counts as scheduled; every platform published is posted;
 * any failed platform (once nothing is still in flight) or any group brain refused or never
 * answered is a failed publish that a retry continues, never a silent success.
 */
class SettleSocialreinsSubjectAction
{
    public function handle(Post|Video $subject, bool $fromJob = false): void
    {
        DB::transaction(function () use ($subject, $fromJob): void {
            $locked = $subject::query()->whereKey($subject->getKey())->lockForUpdate()->first();

            if ($locked === null || (! $fromJob && $this->jobIsWorking($locked))) {
                return;
            }

            $rows = SocialreinsResults::rowsOf($locked);
            if ($rows === []) {
                return;
            }

            $platforms = SocialreinsResults::byPlatform($rows);
            $failed = SocialreinsResults::withState($platforms, SocialreinsResults::FAILED);
            $partial = SocialreinsResults::withState($platforms, SocialreinsResults::PARTIAL);
            $unknown = SocialreinsResults::withState($platforms, SocialreinsResults::UNKNOWN);
            $waiting = SocialreinsResults::withState($platforms, SocialreinsResults::WAITING);
            $ok = SocialreinsResults::withState($platforms, SocialreinsResults::OK);
            $open = $this->unfinishedRows($rows);

            if ($open !== []) {
                $this->save(
                    $locked,
                    $ok->isNotEmpty() || $waiting->isNotEmpty() ? 'scheduled' : null,
                    'failed',
                    implode(' ', array_map(
                        fn (SocialreinsGroup $row): string => "{$row->language}: ".($row->error ?? 'not sent yet.'),
                        $open,
                    )),
                );

                return;
            }

            if (($failed->isNotEmpty() || $partial->isNotEmpty() || $unknown->isNotEmpty()) && $waiting->isEmpty()) {
                $message = [];
                if ($failed->isNotEmpty()) {
                    $message[] = 'SocialReins failed on '.$failed->map(
                        fn (array $p, string $key): string => explode(':', $key, 2)[1].($p['detail'] !== '' ? " ({$p['detail']})" : ''),
                    )->implode(', ').'. Retry sends only those.';
                }
                if ($partial->isNotEmpty()) {
                    $message[] = self::partialMessage($partial);
                }
                if ($unknown->isNotEmpty()) {
                    $message[] = self::unknownMessage($unknown);
                }
                $this->save($locked, $ok->isNotEmpty() || $partial->isNotEmpty() || $unknown->isNotEmpty() ? 'scheduled' : null, 'failed', implode(' ', $message));

                return;
            }

            if ($waiting->isEmpty() && $ok->isEmpty()) {
                return;
            }

            $this->save($locked, $waiting->isEmpty() ? 'posted' : 'scheduled', 'succeeded', null);
        });
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $partial
     */
    public static function partialMessage(Collection $partial): string
    {
        return 'Partly published in SocialReins: '.$partial->map(
            fn (array $p, string $key): string => explode(':', $key, 2)[1]." (group {$p['group_id']}"
                .($p['detail'] !== '' ? ", {$p['detail']}" : '').')',
        )->implode(', ').'. Some accounts or thread parts are already live. Retry continues only the failed '
            .'accounts and the rest of the thread; nothing already published is sent again.';
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $unknown
     */
    public static function unknownMessage(Collection $unknown): string
    {
        return 'Check the platform: SocialReins cannot tell whether '.$unknown->map(
            fn (array $p, string $key): string => explode(':', $key, 2)[1]." (group {$p['group_id']}".($p['detail'] !== '' ? ", {$p['detail']}" : '').')',
        )->implode(', ').' already posted (outcome unknown), so it was not retried. Look at the account; '
            .'if it is not there, retry from SocialReins.';
    }

    private function hasPendingPostsyncerGroups(Post|Video $subject): bool
    {
        if (! is_array($subject->publish_progress['split'] ?? null)) {
            return false;
        }

        foreach ($subject->postsyncer['groups'] ?? [] as $group) {
            if (is_array($group) && ! in_array($group['status'] ?? null, SyncScheduledPostsAction::TERMINAL_GROUP_STATUSES, true)) {
                return true;
            }
        }

        return false;
    }

    private function save(Post|Video $subject, ?string $status, string $state, ?string $error): void
    {
        $values = [
            'publish_state' => $state,
            'publish_error' => $error,
        ];

        // A split post stays scheduled while PostSyncer groups are still pending, so the
        // PostSyncer sync keeps checking them and marks it posted later.
        if ($status === 'posted' && $this->hasPendingPostsyncerGroups($subject)) {
            $status = 'scheduled';
        }

        if ($status !== null) {
            $values['status'] = $status;
        }

        // Keep the progress state in step, so readers of the raw record never see a
        // succeeded publish as failed. canRetryPublish() still needs 'failed' on a failure.
        $progress = $subject->publish_progress;
        if (is_array($progress) && ($progress['driver'] ?? null) === SocialreinsConfig::DRIVER) {
            $values['publish_progress'] = ['state' => $state] + $progress;
        }

        // Read before the save: only a run still in flight leaves its request approved.
        $inFlight = in_array($subject->publish_state, ['queued', 'running'], true);

        $subject->forceFill($values)->save();

        if ($subject instanceof Post) {
            self::closeTelegramRequest($subject, $state, $error, $inFlight);
        }
    }

    /**
     * Close the Telegram request this run was queued for (its id is in the stored options, so
     * a webhook or the reconcile can find it). As on PostSyncer, a post handed over counts as
     * published whether it went out now or is scheduled: /cancel can no longer cancel it. A
     * split post only gets here after its PostSyncer half succeeded. Call under the post's lock.
     *
     * A late settle (webhook, reconcile) of a run that already closed its request finds the
     * post no longer in flight: it may still turn a failed request into a published one, but
     * an approved request was approved again since, for a publish that has not started.
     */
    public static function closeTelegramRequest(Post $post, string $publishState, ?string $error, bool $inFlight = true): void
    {
        $options = $post->publish_progress['options'] ?? null;

        TelegramPublishRequests::update(
            $post,
            $publishState === 'succeeded' ? TelegramPostRequest::PUBLISHED : TelegramPostRequest::FAILED,
            $publishState === 'succeeded' ? null : $error,
            is_array($options) ? TelegramPublishRequests::idFrom($options) : null,
            $inFlight ? [TelegramPostRequest::APPROVED, TelegramPostRequest::FAILED] : [TelegramPostRequest::FAILED],
        );
    }

    /**
     * A group row newer than the last created one in its language that was refused or never
     * answered. Older refused rows were superseded by a later attempt.
     *
     * @param  list<SocialreinsGroup>  $rows
     * @return list<SocialreinsGroup>
     */
    private function unfinishedRows(array $rows): array
    {
        $lastCreated = [];
        foreach ($rows as $row) {
            if ($row->state === SocialreinsGroup::CREATED) {
                $lastCreated[$row->language] = $row->sequence;
            }
        }

        return array_values(array_filter(
            $rows,
            fn (SocialreinsGroup $row): bool => $row->state !== SocialreinsGroup::CREATED
                && $row->sequence > ($lastCreated[$row->language] ?? 0)
                && ! $this->supersededByLater($rows, $row),
        ));
    }

    /** @param  list<SocialreinsGroup>  $rows */
    private function supersededByLater(array $rows, SocialreinsGroup $row): bool
    {
        foreach ($rows as $other) {
            if ($other->language === $row->language && $other->sequence > $row->sequence) {
                return true;
            }
        }

        return false;
    }

    private function jobIsWorking(Post|Video $subject): bool
    {
        return in_array($subject->publish_state, ['queued', 'running'], true)
            && $subject->updated_at?->isAfter(now()->subSeconds(PublishSocialreinsJob::LEASE_SECONDS));
    }
}
