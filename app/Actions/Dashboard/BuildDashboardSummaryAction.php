<?php

namespace App\Actions\Dashboard;

use App\Data\Calendar\CalendarEventData;
use App\Data\Dashboard\DashboardSummaryData;
use App\Models\Idea;
use App\Models\Post;
use App\Models\ScratchpadEntry;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Calendar\CollectCalendarOccurrences;
use Carbon\CarbonImmutable;

/**
 * Pipeline counts and the next scheduled posts/videos for the dashboard.
 */
final class BuildDashboardSummaryAction
{
    public function __construct(
        private readonly CollectCalendarOccurrences $occurrences,
    ) {}

    public function handle(Workspace $workspace): DashboardSummaryData
    {
        $timezone = $this->timezone($workspace);

        return new DashboardSummaryData(
            scratchpad: $this->scratchpadCounts($workspace),
            videos: $this->videoCounts($workspace),
            posts: $this->postCounts($workspace),
            upcoming: $this->upcoming($workspace, $timezone),
            timezone: $timezone,
        );
    }

    public function timezone(Workspace $workspace): string
    {
        $timezone = trim($workspace->timezone);

        return $timezone !== '' ? $timezone : 'Asia/Dhaka';
    }

    /**
     * @return array{untriaged: int, total: int}
     */
    private function scratchpadCounts(Workspace $workspace): array
    {
        $counts = ScratchpadEntry::query()
            ->where('workspace_id', $workspace->id)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $untriaged = (int) ($counts['new'] ?? 0);

        return [
            'untriaged' => $untriaged,
            'total' => (int) $counts->sum(),
        ];
    }

    /**
     * @return array{ideation: int, pending: int, ready: int, recorded: int, scheduled: int, posted: int, total: int}
     */
    private function videoCounts(Workspace $workspace): array
    {
        $counts = $this->statusCounts(Video::class, $workspace);
        $pending = (int) ($counts['pending'] ?? 0);
        $ready = (int) ($counts['ready'] ?? 0);
        $recorded = (int) ($counts['recorded'] ?? 0);
        $scheduled = (int) ($counts['scheduled'] ?? 0);
        $posted = (int) ($counts['posted'] ?? 0);

        return [
            'ideation' => $this->openIdeaCount($workspace, 'video'),
            'pending' => $pending,
            'ready' => $ready,
            'recorded' => $recorded,
            'scheduled' => $scheduled,
            'posted' => $posted,
            'total' => $pending + $ready + $recorded + $scheduled + $posted,
        ];
    }

    /**
     * @return array{ideation: int, draft: int, scheduled: int, posted: int}
     */
    private function postCounts(Workspace $workspace): array
    {
        $counts = $this->statusCounts(Post::class, $workspace);

        return [
            'ideation' => $this->openIdeaCount($workspace, 'post'),
            'draft' => (int) ($counts['draft'] ?? 0) + (int) ($counts['ready'] ?? 0),
            'scheduled' => (int) ($counts['scheduled'] ?? 0),
            'posted' => (int) ($counts['posted'] ?? 0),
        ];
    }

    /**
     * @param  class-string<Post|Video>  $model
     * @return array<string, int>
     */
    private function statusCounts(string $model, Workspace $workspace): array
    {
        return $model::query()
            ->where('workspace_id', $workspace->id)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    private function openIdeaCount(Workspace $workspace, string $kind): int
    {
        return Idea::query()
            ->where('workspace_id', $workspace->id)
            ->where('kind', $kind)
            ->where('status', 'open')
            ->count();
    }

    /**
     * @return list<array{kind: string, id: int, human_id: string, title: string, state: string, at: string, date: string, href: string}>
     */
    private function upcoming(Workspace $workspace, string $timezone): array
    {
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $horizon = $today->addDays(14)->endOfDay();
        $events = [];

        foreach (Post::query()->where('workspace_id', $workspace->id)->where('status', 'scheduled')->whereNotNull('postsyncer')->get() as $post) {
            foreach ($this->occurrences->fromRecord('post', $post->id, $post->human_id, $post->title, $post->postsyncer, $timezone) as $event) {
                if ($this->isUpcoming($event, $today, $horizon, $timezone)) {
                    $events[] = $event;
                }
            }
        }

        foreach (Video::query()->where('workspace_id', $workspace->id)->where('status', 'scheduled')->whereNotNull('postsyncer')->get() as $video) {
            foreach ($this->occurrences->fromRecord('video', $video->id, $video->human_id, $video->title, $video->postsyncer, $timezone) as $event) {
                if ($this->isUpcoming($event, $today, $horizon, $timezone)) {
                    $events[] = $event;
                }
            }
        }

        usort($events, function (CalendarEventData $left, CalendarEventData $right): int {
            return [$left->at, $left->kind, $left->humanId] <=> [$right->at, $right->kind, $right->humanId];
        });

        return array_map(
            fn (CalendarEventData $event): array => $event->toArray(),
            array_slice($events, 0, 5),
        );
    }

    private function isUpcoming(
        CalendarEventData $event,
        CarbonImmutable $today,
        CarbonImmutable $horizon,
        string $timezone,
    ): bool {
        if ($event->state !== 'scheduled') {
            return false;
        }

        $day = CarbonImmutable::parse($event->date, $timezone)->startOfDay();

        return $day->greaterThanOrEqualTo($today) && $day->lessThanOrEqualTo($horizon);
    }
}
