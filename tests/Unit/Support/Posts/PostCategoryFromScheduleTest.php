<?php

namespace Tests\Unit\Support\Posts;

use App\Support\Posts\PostCategoryFromSchedule;
use PHPUnit\Framework\TestCase;

class PostCategoryFromScheduleTest extends TestCase
{
    public function test_it_maps_the_earliest_local_hour_to_a_category(): void
    {
        $at = fn (string ...$times): array => ['groups' => array_map(fn (string $t): array => ['scheduled_at' => $t], $times)];

        $this->assertSame('default', PostCategoryFromSchedule::guess(null, 'Asia/Dhaka'));
        $this->assertSame('default', PostCategoryFromSchedule::guess(['groups' => [['post_id' => '1']]], 'Asia/Dhaka'));
        $this->assertSame('morning', PostCategoryFromSchedule::guess($at('2026-10-26T09:12:00+06:00'), 'Asia/Dhaka'));
        // 03:30 UTC is 09:30 in Dhaka.
        $this->assertSame('morning', PostCategoryFromSchedule::guess($at('2026-10-26T03:30:00Z'), 'Asia/Dhaka'));
        $this->assertSame('evening_carousel', PostCategoryFromSchedule::guess($at('2026-10-26T20:53:00+06:00', '2026-10-26T21:10:00+06:00'), 'Asia/Dhaka'));
        $this->assertSame('morning', PostCategoryFromSchedule::guess($at('2026-10-26T20:53:00+06:00', '2026-10-26T09:05:00+06:00'), 'Asia/Dhaka'));
        $this->assertSame('default', PostCategoryFromSchedule::guess($at('2026-10-26T13:00:00+06:00'), 'Asia/Dhaka'));
        $this->assertSame('default', PostCategoryFromSchedule::guess($at('2026-10-26T23:00:00+06:00'), 'Asia/Dhaka'));
    }
}
