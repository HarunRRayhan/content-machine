<?php

namespace App\Actions\Calendar;

use App\Data\Calendar\CalendarEventData;
use App\Models\Post;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Calendar\CollectCalendarOccurrences;
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

        foreach (Post::query()->where('workspace_id', $workspace->id)->whereNotNull('postsyncer')->get() as $post) {
            foreach ($this->occurrences->fromRecord(
                'post',
                $post->id,
                $post->human_id,
                $post->title,
                $post->postsyncer,
                $timezone,
            ) as $event) {
                $events[] = $event;
            }
        }

        foreach (Video::query()->where('workspace_id', $workspace->id)->whereNotNull('postsyncer')->get() as $video) {
            foreach ($this->occurrences->fromRecord(
                'video',
                $video->id,
                $video->human_id,
                $video->title,
                $video->postsyncer,
                $timezone,
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
