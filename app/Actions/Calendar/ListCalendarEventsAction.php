<?php

namespace App\Actions\Calendar;

use App\Data\Calendar\CalendarEventData;
use App\Models\Post;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Calendar\CollectCalendarOccurrences;
use App\Support\Content\PublishGroups;
use Carbon\CarbonImmutable;

/**
 * Scheduled and published posts/videos that fall on the visible month grid.
 */
final class ListCalendarEventsAction
{
    public function __construct(
        private readonly CollectCalendarOccurrences $occurrences,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function handle(Workspace $workspace, int $year, int $month): array
    {
        $timezone = $this->timezone($workspace);
        $start = CarbonImmutable::create($year, $month, 1, 0, 0, 0, $timezone);
        $gridStart = $start->startOfWeek(CarbonImmutable::SUNDAY)->startOfDay();
        $gridEnd = $start->endOfMonth()->endOfWeek(CarbonImmutable::SATURDAY)->endOfDay();

        $events = [];

        $rows = PublishGroups::rowsBySubject('post', PublishGroups::subjectsBetween($workspace, 'post', $gridStart, $gridEnd));
        foreach (Post::query()->where('workspace_id', $workspace->id)
            ->where(fn ($query) => $query->whereNotNull('postsyncer')->orWhereIn('id', array_keys($rows)))->get() as $post) {
            foreach ($this->occurrences->fromRecord(
                'post',
                $post->id,
                $post->human_id,
                $post->title,
                ['groups' => PublishGroups::of($post, $rows[$post->id] ?? [])],
                $timezone,
                'post_'.$post->category,
            ) as $event) {
                $events[] = $event;
            }
        }

        $rows = PublishGroups::rowsBySubject('video', PublishGroups::subjectsBetween($workspace, 'video', $gridStart, $gridEnd));
        foreach (Video::query()->where('workspace_id', $workspace->id)
            ->where(fn ($query) => $query->whereNotNull('postsyncer')->orWhereIn('id', array_keys($rows)))->get() as $video) {
            foreach ($this->occurrences->fromRecord(
                'video',
                $video->id,
                $video->human_id,
                $video->title,
                ['groups' => PublishGroups::of($video, $rows[$video->id] ?? [])],
                $timezone,
                $video->language === 'en' ? 'video_en' : 'video_bn',
            ) as $event) {
                $events[] = $event;
            }
        }

        $inGrid = array_values(array_filter(
            $events,
            function (CalendarEventData $event) use ($gridStart, $gridEnd, $timezone): bool {
                $day = CarbonImmutable::parse($event->date, $timezone)->startOfDay();

                return $day->greaterThanOrEqualTo($gridStart) && $day->lessThanOrEqualTo($gridEnd);
            },
        ));

        usort($inGrid, function (CalendarEventData $left, CalendarEventData $right): int {
            return [$left->at, $left->kind, $left->humanId] <=> [$right->at, $right->kind, $right->humanId];
        });

        return array_map(
            fn (CalendarEventData $event): array => $event->toArray(),
            $inGrid,
        );
    }

    public function timezone(Workspace $workspace): string
    {
        $timezone = trim($workspace->timezone);

        return $timezone !== '' ? $timezone : 'Asia/Dhaka';
    }
}
