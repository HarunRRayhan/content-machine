<?php

namespace App\Support\Content;

use App\Models\Post;
use App\Models\SocialreinsGroup;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Socialreins\SocialreinsResults;
use Carbon\CarbonImmutable;

/**
 * When and where a post or video is scheduled or published, across both drivers: its PostSyncer
 * groups followed by its SocialReins posts in the same shape (`post_id`, `status`,
 * `scheduled_at`, `published_at`, `platforms`, `language`). Readers that only care about the
 * schedule (calendar, dashboard, the published lock) use this instead of `postsyncer.groups`.
 */
final class PublishGroups
{
    /**
     * @param  list<SocialreinsGroup>|null  $rows  the subject's SocialReins rows when already loaded (rowsByWorkspace)
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
     * Every SocialReins row of a workspace's posts or videos in one query, for readers that list
     * many subjects.
     *
     * @param  'post'|'video'  $type
     * @return array<int, list<SocialreinsGroup>> keyed by subject id, each in sequence order
     */
    public static function rowsByWorkspace(Workspace $workspace, string $type): array
    {
        $bySubject = [];

        foreach (SocialreinsGroup::query()
            ->where('workspace_id', $workspace->id)
            ->where('subject_type', $type)
            ->orderBy('sequence')
            ->get() as $row) {
            $bySubject[$row->subject_id][] = $row;
        }

        return $bySubject;
    }
}
