<?php

namespace Tests\Unit\Actions\Calendar;

use App\Actions\Calendar\ListCalendarEventsAction;
use App\Models\Post;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Calendar\CollectCalendarOccurrences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListCalendarEventsActionTest extends TestCase
{
    use RefreshDatabase;

    private function action(): ListCalendarEventsAction
    {
        return new ListCalendarEventsAction(new CollectCalendarOccurrences);
    }

    public function test_it_lists_a_scheduled_post_and_a_published_video_on_the_same_day(): void
    {
        $workspace = Workspace::factory()->create(['timezone' => 'Asia/Dhaka']);

        $post = Post::factory()->for($workspace)->create([
            'title' => 'Morning post',
            'human_id' => 'P-50',
            'status' => 'scheduled',
            'postsyncer' => [
                'groups' => [
                    [
                        'post_id' => '1',
                        'scheduled_at' => '2026-08-26T09:00:00+06:00',
                        'language' => 'bangla',
                    ],
                    [
                        'post_id' => '2',
                        'scheduled_at' => '2026-08-26T09:00:00+06:00',
                        'language' => 'english',
                    ],
                ],
            ],
        ]);

        $video = Video::factory()->for($workspace)->create([
            'title' => 'Evening video',
            'human_id' => 'BV-50',
            'status' => 'posted',
            'postsyncer' => [
                'groups' => [
                    [
                        'post_id' => '3',
                        'scheduled_at' => '2026-08-26T17:45:00+06:00',
                        'published_at' => '2026-08-26T17:46:00+06:00',
                    ],
                ],
            ],
        ]);

        $events = $this->action()->handle($workspace, 2026, 8);

        $this->assertCount(2, $events);
        $this->assertSame('P-50', $events[0]['human_id']);
        $this->assertSame('scheduled', $events[0]['state']);
        $this->assertSame('/posts/P-50', $events[0]['href']);
        $this->assertSame($post->id, $events[0]['id']);
        $this->assertSame('BV-50', $events[1]['human_id']);
        $this->assertSame('published', $events[1]['state']);
        $this->assertSame('/videos/BV-50', $events[1]['href']);
        $this->assertSame($video->id, $events[1]['id']);
    }

    public function test_it_shows_one_chip_per_post_no_matter_how_many_groups_or_retries(): void
    {
        $workspace = Workspace::factory()->create(['timezone' => 'Asia/Dhaka']);

        Post::factory()->for($workspace)->create([
            'title' => 'Claude Fable',
            'human_id' => 'P-71',
            'status' => 'posted',
            'postsyncer' => [
                'groups' => [
                    ['post_id' => '1', 'scheduled_at' => '2026-09-03T13:21:00+06:00', 'published_at' => '2026-09-03T13:21:00+06:00'],
                    ['post_id' => '2', 'scheduled_at' => '2026-09-03T14:24:00+06:00', 'published_at' => '2026-09-03T14:24:00+06:00'],
                    ['post_id' => '3', 'scheduled_at' => '2026-09-03T15:00:00+06:00', 'published_at' => '2026-09-03T15:00:00+06:00'],
                    ['post_id' => '4', 'scheduled_at' => '2026-09-03T15:11:00+06:00', 'published_at' => '2026-09-03T15:11:00+06:00'],
                    ['post_id' => '5', 'scheduled_at' => '2026-09-03T15:12:00+06:00', 'published_at' => '2026-09-03T15:12:00+06:00'],
                    ['post_id' => '6', 'scheduled_at' => '2026-09-03T15:38:00+06:00', 'published_at' => '2026-09-03T15:38:00+06:00'],
                ],
            ],
        ]);

        $events = $this->action()->handle($workspace, 2026, 9);

        $this->assertCount(1, $events);
        $this->assertSame('P-71', $events[0]['human_id']);
        $this->assertSame('published', $events[0]['state']);
        $this->assertSame('2026-09-03', $events[0]['date']);
        $this->assertSame('2026-09-03T15:38:00+06:00', $events[0]['at']);
    }

    public function test_it_collapses_scheduled_groups_at_different_times_into_one_chip(): void
    {
        $workspace = Workspace::factory()->create(['timezone' => 'Asia/Dhaka']);

        Post::factory()->for($workspace)->create([
            'title' => 'Drifted schedule',
            'human_id' => 'P-72',
            'status' => 'scheduled',
            'postsyncer' => [
                'groups' => [
                    ['post_id' => '1', 'scheduled_at' => '2026-09-04T09:23:00+06:00'],
                    ['post_id' => '2', 'scheduled_at' => '2026-09-04T10:15:00+06:00'],
                ],
            ],
        ]);

        $events = $this->action()->handle($workspace, 2026, 9);

        $this->assertCount(1, $events);
        $this->assertSame('P-72', $events[0]['human_id']);
        $this->assertSame('scheduled', $events[0]['state']);
        $this->assertSame('2026-09-04', $events[0]['date']);
    }

    public function test_published_groups_win_over_scheduled_groups_on_the_same_record(): void
    {
        $workspace = Workspace::factory()->create(['timezone' => 'Asia/Dhaka']);

        Post::factory()->for($workspace)->create([
            'title' => 'Partially live',
            'human_id' => 'P-73',
            'status' => 'posted',
            'postsyncer' => [
                'groups' => [
                    ['post_id' => '1', 'scheduled_at' => '2026-09-05T09:00:00+06:00'],
                    ['post_id' => '2', 'scheduled_at' => '2026-09-05T09:00:00+06:00', 'published_at' => '2026-09-05T09:05:00+06:00'],
                ],
            ],
        ]);

        $events = $this->action()->handle($workspace, 2026, 9);

        $this->assertCount(1, $events);
        $this->assertSame('P-73', $events[0]['human_id']);
        $this->assertSame('published', $events[0]['state']);
    }

    public function test_it_ignores_other_workspaces_and_records_without_dates(): void
    {
        $workspace = Workspace::factory()->create(['timezone' => 'Asia/Dhaka']);
        $other = Workspace::factory()->create(['timezone' => 'Asia/Dhaka']);

        Post::factory()->for($workspace)->create([
            'title' => 'Draft',
            'status' => 'draft',
        ]);

        Post::factory()->for($other)->create([
            'title' => 'Someone else',
            'status' => 'scheduled',
            'postsyncer' => [
                'groups' => [
                    ['scheduled_at' => '2026-08-26T09:00:00+06:00'],
                ],
            ],
        ]);

        $this->assertSame([], $this->action()->handle($workspace, 2026, 8));
    }
}
