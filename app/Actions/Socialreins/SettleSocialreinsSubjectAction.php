<?php

namespace App\Actions\Socialreins;

use App\Jobs\PublishSocialreinsJob;
use App\Models\Post;
use App\Models\SocialreinsGroup;
use App\Models\Video;
use App\Support\Socialreins\SocialreinsResults;
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

            if ($failed->isNotEmpty() && $waiting->isEmpty()) {
                $message = 'SocialReins failed on '.$failed->map(
                    fn (array $p, string $key): string => explode(':', $key, 2)[1].($p['detail'] !== '' ? " ({$p['detail']})" : ''),
                )->implode(', ').'. Retry sends only those.';
                $this->save($locked, $ok->isNotEmpty() ? 'scheduled' : null, 'failed', $message);

                return;
            }

            if ($waiting->isEmpty() && $ok->isEmpty()) {
                return;
            }

            $this->save($locked, $waiting->isEmpty() ? 'posted' : 'scheduled', 'succeeded', null);
        });
    }

    private function save(Post|Video $subject, ?string $status, string $state, ?string $error): void
    {
        $values = [
            'publish_state' => $state,
            'publish_error' => $error,
        ];

        if ($status !== null) {
            $values['status'] = $status;
        }

        $subject->forceFill($values)->save();
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
