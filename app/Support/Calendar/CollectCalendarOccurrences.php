<?php

namespace App\Support\Calendar;

use App\Data\Calendar\CalendarEventData;
use Carbon\CarbonImmutable;

/**
 * Turns PostSyncer groups on a post or video into a single calendar occurrence.
 * One post or video is always one chip: every PostSyncer group (platform,
 * language, or retry) collapses to one event. Published wins over scheduled.
 */
final class CollectCalendarOccurrences
{
    /**
     * @param  array<string, mixed>|null  $postsyncer
     * @return list<CalendarEventData>
     */
    public function fromRecord(
        string $kind,
        int $id,
        string $humanId,
        string $title,
        ?array $postsyncer,
        string $timezone,
    ): array {
        $groups = $postsyncer['groups'] ?? null;

        if (! is_array($groups)) {
            return [];
        }

        $events = [];

        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $event = $this->fromGroup($kind, $id, $humanId, $title, $group, $timezone);

            if ($event === null) {
                continue;
            }

            $events[] = $event;
        }

        if ($events === []) {
            return [];
        }

        $published = array_values(array_filter(
            $events,
            static fn (CalendarEventData $event): bool => $event->state === 'published',
        ));

        if ($published !== []) {
            return [$this->latest($published)];
        }

        return [$this->earliest($events)];
    }

    /**
     * @param  array<string, mixed>  $group
     */
    private function fromGroup(
        string $kind,
        int $id,
        string $humanId,
        string $title,
        array $group,
        string $timezone,
    ): ?CalendarEventData {
        $publishedAt = $this->stringOrNull($group['published_at'] ?? null);
        $scheduledAt = $this->stringOrNull($group['scheduled_at'] ?? null);

        if ($publishedAt !== null) {
            $state = 'published';
            $raw = $publishedAt;
        } elseif ($scheduledAt !== null) {
            $state = 'scheduled';
            $raw = $scheduledAt;
        } else {
            return null;
        }

        try {
            $at = CarbonImmutable::parse($raw, $timezone)->timezone($timezone);
        } catch (\Throwable) {
            return null;
        }

        return new CalendarEventData(
            kind: $kind,
            id: $id,
            humanId: $humanId,
            title: $title,
            state: $state,
            at: $at->toIso8601String(),
            date: $at->toDateString(),
        );
    }

    /**
     * @param  list<CalendarEventData>  $events
     */
    private function latest(array $events): CalendarEventData
    {
        $best = $events[0];

        foreach (array_slice($events, 1) as $event) {
            if ($this->compareAt($event, $best) > 0) {
                $best = $event;
            }
        }

        return $best;
    }

    /**
     * @param  list<CalendarEventData>  $events
     */
    private function earliest(array $events): CalendarEventData
    {
        $best = $events[0];

        foreach (array_slice($events, 1) as $event) {
            if ($this->compareAt($event, $best) < 0) {
                $best = $event;
            }
        }

        return $best;
    }

    private function compareAt(CalendarEventData $left, CalendarEventData $right): int
    {
        try {
            $leftAt = CarbonImmutable::parse($left->at);
            $rightAt = CarbonImmutable::parse($right->at);

            if ($leftAt->equalTo($rightAt)) {
                return 0;
            }

            return $leftAt->greaterThan($rightAt) ? 1 : -1;
        } catch (\Throwable) {
            return strcmp($left->at, $right->at);
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
