<?php

namespace App\Support\Calendar;

use App\Data\Calendar\CalendarEventData;
use Carbon\CarbonImmutable;

/**
 * Turns PostSyncer groups on a post or video into calendar occurrences.
 * Published wins over scheduled on the same group. Two groups at the
 * same instant collapse to one chip.
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

        $seen = [];
        $events = [];

        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $event = $this->fromGroup($kind, $id, $humanId, $title, $group, $timezone);

            if ($event === null) {
                continue;
            }

            $key = $event->kind.'|'.$event->id.'|'.$event->date.'|'.$event->state.'|'.$event->at;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $events[] = $event;
        }

        return $events;
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

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
