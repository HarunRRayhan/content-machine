<?php

namespace Tests\Feature\Console;

use App\Models\Post;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncScheduledPostsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_marks_live_scheduled_posts_and_videos_as_posted(): void
    {
        $workspace = Workspace::factory()->create(['settings' => []]);
        PostsyncerConfig::write($workspace, [
            'api_key' => 'test-api-key',
            'api_base' => 'https://postsyncer.com/api/v1',
        ]);
        $workspace->refresh();

        $post = Post::factory()->for($workspace)->create([
            'status' => 'scheduled',
            'postsyncer' => [
                'groups' => [
                    [
                        'post_id' => '88',
                        'status' => 'SCHEDULED',
                        'platforms' => ['facebook'],
                        'language' => 'bangla',
                    ],
                ],
            ],
        ]);

        $video = Video::factory()->for($workspace)->create([
            'status' => 'scheduled',
            'postsyncer' => [
                'groups' => [
                    [
                        'post_id' => '99',
                        'status' => 'SCHEDULED',
                        'platforms' => ['youtube'],
                        'language' => 'bangla',
                    ],
                ],
            ],
        ]);

        Http::fake([
            'postsyncer.com/api/v1/posts/88' => Http::response([
                'id' => 88,
                'status' => 'PUBLISHED',
            ], 200),
            'postsyncer.com/api/v1/posts/99' => Http::response([
                'id' => 99,
                'status' => 'PUBLISHED',
            ], 200),
        ]);

        $this->artisan('postsyncer:sync-scheduled')
            ->expectsOutputToContain('Marked 1 scheduled post(s) as posted.')
            ->expectsOutputToContain('Marked 1 scheduled video(s) as posted.')
            ->assertSuccessful();

        $this->assertSame('posted', $post->fresh()->status);
        $this->assertSame('posted', $video->fresh()->status);
    }

    public function test_sync_is_scheduled_every_five_minutes(): void
    {
        $event = collect(app(Schedule::class)->events())->first(
            fn ($event): bool => str_contains((string) $event->command, 'postsyncer:sync-scheduled'),
        );

        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
    }
}
