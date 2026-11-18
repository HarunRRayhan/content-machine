<?php

namespace App\Support\Posts;

use Carbon\CarbonImmutable;

/**
 * Guesses a post's category from its earliest PostSyncer scheduled_at, in the
 * workspace timezone: 09:00-11:59 is a morning post, 20:00-22:59 an evening
 * carousel, anything else (or no schedule) the default.
 */
final class PostCategoryFromSchedule
{
    /**
     * @param  array<string, mixed>|null  $postsyncer
     */
    public static function guess(?array $postsyncer, string $timezone): string
    {
        $groups = is_array($postsyncer) ? ($postsyncer['groups'] ?? null) : null;
        $earliest = null;

        foreach (is_array($groups) ? $groups : [] as $group) {
            $raw = is_array($group) ? ($group['scheduled_at'] ?? null) : null;

            if (! is_string($raw) || $raw === '') {
                continue;
            }

            try {
                $at = CarbonImmutable::parse($raw, $timezone)->timezone($timezone);
            } catch (\Throwable) {
                continue;
            }

            if ($earliest === null || $at->lessThan($earliest)) {
                $earliest = $at;
            }
        }

        return match (true) {
            $earliest === null => 'default',
            $earliest->hour >= 9 && $earliest->hour <= 11 => 'morning',
            $earliest->hour >= 20 && $earliest->hour <= 22 => 'evening_carousel',
            default => 'default',
        };
    }
}
