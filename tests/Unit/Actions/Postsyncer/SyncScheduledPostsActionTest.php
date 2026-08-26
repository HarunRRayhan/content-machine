<?php

namespace Tests\Unit\Actions\Postsyncer;

use App\Actions\Postsyncer\SyncScheduledPostsAction;
use App\Models\Post;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncScheduledPostsActionTest extends TestCase
{
    use RefreshDatabase;

    private function configureWorkspace(Workspace $workspace): void
    {
        PostsyncerConfig::write($workspace, [
            'api_key' => 'test-api-key',
            'api_base' => 'https://postsyncer.com/api/v1',
        ]);
        $workspace->refresh();
    }

    public function test_it_marks_a_post_posted_when_every_group_is_published(): void
    {
        $workspace = Workspace::factory()->create(['settings' => []]);
        $this->configureWorkspace($workspace);

        $post = Post::factory()->for($workspace)->create([
            'status' => 'scheduled',
            'postsyncer' => [
                'groups' => [
                    [
                        'post_id' => '130052',
                        'status' => 'SCHEDULED',
                        'scheduled_at' => '2026-08-26T09:00:00+06:00',
                        'platforms' => ['facebook'],
                        'language' => 'bangla',
                    ],
                    [
                        'post_id' => '130053',
                        'status' => 'SCHEDULED',
                        'scheduled_at' => '2026-08-26T09:00:00+06:00',
                        'platforms' => ['twitter'],
                        'language' => 'english',
                    ],
                ],
            ],
        ]);

        Http::fake([
            'postsyncer.com/api/v1/posts/130052' => Http::response([
                'id' => 130052,
                'status' => 'PUBLISHED',
                'published_at' => '2026-08-26T09:01:00+06:00',
            ], 200),
            'postsyncer.com/api/v1/posts/130053' => Http::response([
                'id' => 130053,
                'status' => 'published',
                'published_at' => '2026-08-26T09:01:00+06:00',
            ], 200),
        ]);

        $marked = (new SyncScheduledPostsAction)->handle();

        $this->assertSame(1, $marked);
        $post->refresh();
        $this->assertSame('posted', $post->status);
        $this->assertSame('PUBLISHED', $post->postsyncer['groups'][0]['status']);
        $this->assertSame('2026-08-26T09:01:00+06:00', $post->postsyncer['groups'][0]['published_at']);
        $this->assertSame('PUBLISHED', $post->postsyncer['groups'][1]['status']);
    }

    public function test_it_leaves_a_post_scheduled_when_a_group_is_still_queued(): void
    {
        $workspace = Workspace::factory()->create(['settings' => []]);
        $this->configureWorkspace($workspace);

        $post = Post::factory()->for($workspace)->create([
            'status' => 'scheduled',
            'postsyncer' => [
                'groups' => [
                    [
                        'post_id' => '130052',
                        'status' => 'SCHEDULED',
                        'platforms' => ['facebook'],
                        'language' => 'bangla',
                    ],
                    [
                        'post_id' => '130053',
                        'status' => 'SCHEDULED',
                        'platforms' => ['twitter'],
                        'language' => 'english',
                    ],
                ],
            ],
        ]);

        Http::fake([
            'postsyncer.com/api/v1/posts/130052' => Http::response([
                'id' => 130052,
                'status' => 'PUBLISHED',
            ], 200),
            'postsyncer.com/api/v1/posts/130053' => Http::response([
                'id' => 130053,
                'status' => 'SCHEDULED',
            ], 200),
        ]);

        $marked = (new SyncScheduledPostsAction)->handle();

        $this->assertSame(0, $marked);
        $post->refresh();
        $this->assertSame('scheduled', $post->status);
        $this->assertSame('PUBLISHED', $post->postsyncer['groups'][0]['status']);
        $this->assertSame('SCHEDULED', $post->postsyncer['groups'][1]['status']);
    }

    public function test_it_skips_a_group_when_the_live_lookup_fails(): void
    {
        $workspace = Workspace::factory()->create(['settings' => []]);
        $this->configureWorkspace($workspace);

        $post = Post::factory()->for($workspace)->create([
            'status' => 'scheduled',
            'postsyncer' => [
                'groups' => [
                    [
                        'post_id' => '130052',
                        'status' => 'SCHEDULED',
                        'platforms' => ['facebook'],
                        'language' => 'bangla',
                    ],
                ],
            ],
        ]);

        Http::fake([
            'postsyncer.com/api/v1/posts/130052' => Http::response(['message' => 'Not found'], 404),
        ]);

        $marked = (new SyncScheduledPostsAction)->handle();

        $this->assertSame(0, $marked);
        $post->refresh();
        $this->assertSame('scheduled', $post->status);
        $this->assertSame('SCHEDULED', $post->postsyncer['groups'][0]['status']);
    }
}
