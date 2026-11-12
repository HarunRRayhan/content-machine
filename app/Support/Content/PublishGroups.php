<?php

namespace App\Support\Content;

use App\Models\Post;
use App\Models\SocialreinsGroup;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Socialreins\SocialreinsResults;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * When and where a post or video is scheduled or published, across both drivers: its PostSyncer
 * groups followed by its SocialReins posts in the same shape (`post_id`, `status`,
 * `scheduled_at`, `published_at`, `platforms`, `language`). Readers that only care about the
 * schedule (calendar, dashboard, the published lock) use this instead of `postsyncer.groups`.
 */
final class PublishGroups
{
    /**
     * @param  list<SocialreinsGroup>|null  $rows  the subject's SocialReins rows when already loaded (rowsBySubject)
     * @return list<array<string, mixed>>
     */
    public static function of(Post|Video $subject, ?array $rows = null): array
    {
        $postsyncer = $subject->postsyncer;
        $groups = is_array($postsyncer) && is_array($postsyncer['groups'] ?? null)
            ? array_values(array_filter($postsyncer['groups'], 'is_array'))
            : [];

        $rows ??= SocialreinsResults::rowsOf($subject);
        $storedAt = [];
        foreach ($rows as $row) {
            foreach ($row->results ?? [] as $postId => $result) {
                $storedAt[(string) $postId] = (int) ($result['at'] ?? 0);
            }
        }

        foreach (SocialreinsResults::presentGroups($subject, $rows) as $group) {
            // A post taken down from SocialReins is no longer scheduled or published anywhere.
            if ($group['status'] === SocialreinsResults::DELETED) {
                continue;
            }

            // Brain keeps no publish time: a scheduled post goes out at its time, an immediate
            // one when its result was stored.
            if (in_array($group['status'], ['published', SocialreinsResults::PARTIAL], true)) {
                $group['published_at'] = $group['scheduled_at'] ?? CarbonImmutable::createFromTimestampUTC($storedAt[$group['post_id']] ?? 0)->toIso8601String();
            }

            $groups[] = $group;
        }

        return $groups;
    }

    /**
     * The SocialReins rows of the given posts or videos in one query, for readers that list many
     * subjects. Only what of() reads is loaded: rows brain created, and of the stored request
     * (the whole payload, captions and media included) just its `scheduled_at`.
     *
     * @param  'post'|'video'  $type
     * @param  array<int, int|string>|Builder<covariant Model>  $subjectIds  model keys, or a subquery selecting them
     * @return array<int, list<SocialreinsGroup>> keyed by subject id, each in sequence order
     */
    public static function rowsBySubject(string $type, array|Builder $subjectIds): array
    {
        $bySubject = [];

        foreach (SocialreinsGroup::query()
            ->where('subject_type', $type)
            ->whereIn('subject_id', $subjectIds)
            ->where('state', SocialreinsGroup::CREATED)
            ->orderBy('sequence')
            ->get([
                'subject_id', 'state', 'language', 'results',
                DB::raw("json_build_object('scheduled_at', request::json->'scheduled_at')::text as request"),
            ]) as $row) {
            $bySubject[$row->subject_id][] = $row;
        }

        return $bySubject;
    }

    /**
     * Subquery for rowsBySubject(): the subjects of a workspace with a SocialReins post scheduled,
     * rescheduled or stored between two moments. Every row of such a subject is then loaded, so a
     * newer group still supersedes an older one.
     *
     * @param  'post'|'video'  $type
     * @return Builder<SocialreinsGroup>
     */
    public static function subjectsBetween(Workspace $workspace, string $type, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        $window = [$from->toIso8601String(), $to->toIso8601String()];

        return SocialreinsGroup::query()
            ->select('subject_id')
            ->where('workspace_id', $workspace->id)
            ->where('subject_type', $type)
            ->where('state', SocialreinsGroup::CREATED)
            ->where(fn (Builder $query) => $query
                ->whereRaw("(request::json->>'scheduled_at')::timestamptz between ? and ?", $window)
                // `results` is an object keyed by post id; an emptied one is stored as [].
                ->orWhereRaw(
                    "exists (select 1 from json_each(case when json_typeof(results) = 'object' then results else '{}'::json end) as r"
                    ." where (r.value->>'scheduled_at')::timestamptz between ? and ?"
                    ." or to_timestamp((r.value->>'at')::bigint) between ? and ?)",
                    [...$window, ...$window],
                ));
    }
}
