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
