<?php

namespace App\Data\Dashboard;

/**
 * Workspace pipeline counts and the next scheduled items for the home page.
 */
final readonly class DashboardSummaryData
{
    /**
     * @param  array{untriaged: int, total: int}  $scratchpad
     * @param  array{ideation: int, pending: int, ready: int, recorded: int, scheduled: int, posted: int, total: int}  $videos
     * @param  array{ideation: int, draft: int, scheduled: int, posted: int}  $posts
     * @param  list<array{kind: string, id: int, human_id: string, title: string, state: string, at: string, date: string, href: string}>  $upcoming
     */
    public function __construct(
        public array $scratchpad,
        public array $videos,
        public array $posts,
        public array $upcoming,
        public string $timezone,
    ) {}

    /**
     * @return array{
     *     scratchpad: array{untriaged: int, total: int},
     *     videos: array{ideation: int, pending: int, ready: int, recorded: int, scheduled: int, posted: int, total: int},
     *     posts: array{ideation: int, draft: int, scheduled: int, posted: int},
     *     upcoming: list<array{kind: string, id: int, human_id: string, title: string, state: string, at: string, date: string, href: string}>,
     *     timezone: string
     * }
     */
    public function toArray(): array
    {
        return [
            'scratchpad' => $this->scratchpad,
            'videos' => $this->videos,
            'posts' => $this->posts,
            'upcoming' => $this->upcoming,
            'timezone' => $this->timezone,
        ];
    }
}
