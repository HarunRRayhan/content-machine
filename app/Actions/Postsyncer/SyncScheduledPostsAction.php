<?php

namespace App\Actions\Postsyncer;

use App\Models\Post;
use App\Models\Video;
use App\Support\Postsyncer\PostsyncerClient;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Postsyncer\PostsyncerException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Live-check scheduled posts and videos against PostSyncer and mark them
 * posted once every stored group is terminal: PUBLISHED, or an
 * operator-confirmed FAILED group that was already verified as delivered
 * (English Instagram often reports FAILED while already live; waiting forever
 * leaves the CM row stuck on Scheduled).
 *
 * PostSyncer allows 60 requests a minute per key and publishing shares that
 * allowance, so a run asks about few groups and only those that can have changed:
 *
 * - PUBLISHED is final and is never asked about again.
 * - A group due in the last day (or with no stored time) is asked about every run.
 * - Everything else is asked about once a day: a group still in the future cannot be
 *   live, so the daily look only picks up a time changed inside PostSyncer; a FAILED
 *   or long-overdue group only changes if PostSyncer delivers it late.
 */
class SyncScheduledPostsAction
{
    /** @var list<string> */
    public const TERMINAL_GROUP_STATUSES = ['PUBLISHED', 'FAILED'];

    /** A third of PostSyncer's per-minute allowance; the rest stays free for publishing. */
    public const MAX_REQUESTS_PER_RUN = 20;

    /** Daily checks never take more than this many of a run's requests from due groups. */
    private const DAILY_CHECK_SHARE = 5;

    /** Fewer requests left than this means something else is using the window: stop. */
    public const RATE_LIMIT_FLOOR = 30;

    private const DUE_MARGIN_MINUTES = 2;

    private const RECENTLY_DUE_HOURS = 24;

    private const DAILY_CHECK_HOURS = 24;

    /**
     * @return array{posts: int, videos: int, requests: int, rate_limited: bool}
     */
    public function handle(): array
    {
        $result = ['posts' => 0, 'videos' => 0, 'requests' => 0, 'rate_limited' => false];

        /** @var list<Post|Video> $records */
        $records = [
            ...Post::query()->where('status', 'scheduled')->with('workspace')->get(),
            ...Video::query()->where('status', 'scheduled')->with('workspace')->get(),
        ];

        /** @var array<int, PostsyncerClient> $clients */
        $clients = [];
        $checked = [];

        foreach ($this->checksForThisRun($records) as $check) {
            $record = $check['record'];
            $client = $clients[$record->workspace_id] ??= new PostsyncerClient(
                PostsyncerConfig::fromWorkspace($record->workspace),
            );
            $live = null;

            try {
                $live = $client->getPost($check['post_id']);
                // PostSyncer has answered both the bare resource and {data: resource}.
                $live = is_array($live['data'] ?? null) ? $live['data'] : $live;
            } catch (PostsyncerException $exception) {
                // Rate limited: stop quietly and change nothing; the next run catches up.
                if ($exception->getCode() === 429) {
                    $result['rate_limited'] = true;

                    break;
                }
            }

            $result['requests']++;
            $checked[spl_object_id($record)] = true;

            if ($this->store($record, $check['post_id'], $live)) {
                $result[$record instanceof Post ? 'posts' : 'videos']++;
            }

            $remaining = $client->rateLimitRemaining();
            if ($remaining !== null && $remaining < self::RATE_LIMIT_FLOOR) {
                $result['rate_limited'] = true;

                break;
            }
        }

        // A record can become complete without a lookup: its SocialReins half settles after
        // every PostSyncer group was already stored as terminal.
        foreach ($records as $record) {
            if (! isset($checked[spl_object_id($record)]) && $this->delivered($record) && $this->store($record)) {
                $result[$record instanceof Post ? 'posts' : 'videos']++;
            }
        }

        return $result;
    }

    /**
     * The groups to ask PostSyncer about now, least recently checked first so every group is
     * reached eventually, capped at the run's request budget.
     *
     * @param  list<Post|Video>  $records
     * @return list<array{record: Post|Video, post_id: string, synced_at: string}>
     */
    private function checksForThisRun(array $records): array
    {
        $due = [];
        $daily = [];
        $now = CarbonImmutable::now();

        foreach ($records as $record) {
            $groups = $record->postsyncer['groups'] ?? null;

            if ($record->workspace === null
                || ! is_array($groups)
                || ! PostsyncerConfig::fromWorkspace($record->workspace)->isConfigured()) {
                continue;
            }

            foreach ($groups as $group) {
                $postId = is_array($group) ? ($group['post_id'] ?? '') : '';
                $status = is_array($group) ? strtoupper((string) ($group['status'] ?? '')) : '';

                if (! is_array($group) || ! is_scalar($postId) || (string) $postId === '' || $status === 'PUBLISHED') {
                    continue;
                }

                $check = [
                    'record' => $record,
                    'post_id' => (string) $postId,
                    'synced_at' => is_string($group['synced_at'] ?? null) ? $group['synced_at'] : '',
                ];
                $when = $this->scheduledAt($group, $record->workspace->timezone);

                // shortcut: a group with no stored time stays in the every-run set until it is
                // terminal; give it a first-seen time if such groups ever pile up.
                if ($status !== 'FAILED' && ($when === null || $when->between(
                    $now->subHours(self::RECENTLY_DUE_HOURS),
                    $now->addMinutes(self::DUE_MARGIN_MINUTES),
                ))) {
                    $due[] = $check;
                } elseif ($check['synced_at'] === ''
                    || $this->parse($check['synced_at'])?->lte($now->subHours(self::DAILY_CHECK_HOURS)) !== false) {
                    $daily[] = $check;
                }
            }
        }

        // ISO-8601 UTC strings sort by time; never-checked ('') sorts first.
        $oldestFirst = fn (array $left, array $right): int => $left['synced_at'] <=> $right['synced_at'];
        usort($due, $oldestFirst);
        usort($daily, $oldestFirst);
        $dueShare = self::MAX_REQUESTS_PER_RUN - self::DAILY_CHECK_SHARE;

        return array_slice([
            ...array_slice($due, 0, $dueShare),
            ...array_slice($daily, 0, self::DAILY_CHECK_SHARE),
            ...array_slice($due, $dueShare),
            ...array_slice($daily, self::DAILY_CHECK_SHARE),
        ], 0, self::MAX_REQUESTS_PER_RUN);
    }

    /**
     * Write one lookup's answer onto the freshly locked row (a publish or reschedule may have
     * rewritten its groups since this run read them) and mark the row posted when it is done.
     *
     * @param  array<string, mixed>|null  $live  null when the lookup failed: only the check time is stored
     * @return bool whether the record was marked posted
     */
    private function store(Post|Video $record, ?string $postId = null, ?array $live = null): bool
    {
        return DB::transaction(function () use ($record, $postId, $live): bool {
            $locked = $record->newQuery()->whereKey($record->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->status !== 'scheduled') {
                return false;
            }

            $stored = $locked->postsyncer ?? [];
            $groups = is_array($stored['groups'] ?? null) ? $stored['groups'] : [];

            foreach ($groups as $index => $group) {
                if ($postId === null || ! is_array($group) || (string) ($group['post_id'] ?? '') !== $postId) {
                    continue;
                }

                $group['synced_at'] = CarbonImmutable::now('UTC')->toIso8601String();

                if ($live !== null) {
                    $group['status'] = strtoupper((string) ($live['status'] ?? $group['status'] ?? ''));
                    if (($group['operator_confirmed'] ?? false) === true) {
                        $group['remote_status'] = $group['status'];
                    }

                    foreach (['scheduled_at', 'published_at'] as $field) {
                        if (isset($live[$field]) && is_scalar($live[$field])) {
                            $group[$field] = (string) $live[$field];
                        }
                    }
                }

                $groups[$index] = $group;
            }

            $locked->postsyncer = array_merge($stored, ['groups' => $groups]);
            $posted = $this->delivered($locked);

            if ($posted) {
                $locked->status = 'posted';
            }

            $locked->save();

            return $posted;
        });
    }

    /**
     * Every stored group is terminal and at least one was delivered. A split post is posted
     * only once its SocialReins half has succeeded too.
     */
    private function delivered(Post|Video $record): bool
    {
        $groups = $record->postsyncer['groups'] ?? null;

        if (! is_array($groups)
            || (is_array($record->publish_progress['split'] ?? null) && $record->publish_state !== 'succeeded')) {
            return false;
        }

        $anyPublished = false;

        foreach ($groups as $group) {
            $status = is_array($group) ? strtoupper((string) ($group['status'] ?? '')) : '';

            if (! is_array($group)
                || ! is_scalar($group['post_id'] ?? null)
                || (string) $group['post_id'] === ''
                || ! in_array($status, self::TERMINAL_GROUP_STATUSES, true)) {
                return false;
            }

            $anyPublished = $anyPublished
                || $status === 'PUBLISHED'
                || ($group['operator_confirmed'] ?? false) === true;
        }

        return $anyPublished;
    }

    /**
     * A time without an offset is read in the workspace's timezone, as the publish path does.
     *
     * @param  array<string, mixed>  $group
     */
    private function scheduledAt(array $group, mixed $timezone): ?CarbonImmutable
    {
        $when = $group['scheduled_at'] ?? null;

        return is_string($when) && $when !== ''
            ? $this->parse($when, is_string($timezone) && trim($timezone) !== '' ? $timezone : 'Asia/Dhaka')
            : null;
    }

    private function parse(string $time, ?string $timezone = null): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($time, $timezone);
        } catch (\Throwable) {
            return null;
        }
    }
}
