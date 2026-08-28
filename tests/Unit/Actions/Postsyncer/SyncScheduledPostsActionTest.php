<?php

namespace Tests\Unit\Actions\Postsyncer;

use App\Actions\Postsyncer\SyncScheduledPostsAction;
use App\Models\Post;
use App\Models\Video;
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

        $this->assertSame(1, $marked['posts']);
        $this->assertSame(0, $marked['videos']);
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

        $this->assertSame(0, $marked['posts']);
        $this->assertSame(0, $marked['videos']);
        $post->refresh();
        $this->assertSame('scheduled', $post->status);
        $this->assertSame('PUBLISHED', $post->postsyncer['groups'][0]['status']);
        $this->assertSame('SCHEDULED', $post->postsyncer['groups'][1]['status']);
    }

    public function test_it_marks_a_post_posted_when_remaining_groups_only_failed(): void
    {
        $workspace = Workspace::factory()->create(['settings' => []]);
        $this->configureWorkspace($workspace);

        $post = Post::factory()->for($workspace)->create([
            'status' => 'scheduled',
            'postsyncer' => [
                'groups' => [
                    [
                        'post_id' => '132730',
                        'status' => 'SCHEDULED',
                        'platforms' => ['facebook'],
                        'language' => 'english',
                    ],
                    [
                        'post_id' => '132731',
                        'status' => 'SCHEDULED',
                        'platforms' => ['instagram'],
                        'language' => 'english',
                    ],
                ],
            ],
        ]);

        Http::fake([
            'postsyncer.com/api/v1/posts/132730' => Http::response([
                'id' => 132730,
                'status' => 'PUBLISHED',
            ], 200),
            'postsyncer.com/api/v1/posts/132731' => Http::response([
                'id' => 132731,
                'status' => 'FAILED',
            ], 200),
        ]);

        $marked = (new SyncScheduledPostsAction)->handle();

        $this->assertSame(1, $marked['posts']);
        $post->refresh();
        $this->assertSame('posted', $post->status);
        $this->assertSame('PUBLISHED', $post->postsyncer['groups'][0]['status']);
        $this->assertSame('FAILED', $post->postsyncer['groups'][1]['status']);
    }

    public function test_it_leaves_a_post_scheduled_when_every_group_failed(): void
    {
        $workspace = Workspace::factory()->create(['settings' => []]);
        $this->configureWorkspace($workspace);

        $post = Post::factory()->for($workspace)->create([
            'status' => 'scheduled',
            'postsyncer' => [
                'groups' => [
                    [
                        'post_id' => '132731',
                        'status' => 'SCHEDULED',
                        'platforms' => ['instagram'],
                        'language' => 'english',
                    ],
                ],
            ],
        ]);

        Http::fake([
            'postsyncer.com/api/v1/posts/132731' => Http::response([
                'id' => 132731,
                'status' => 'FAILED',
            ], 200),
        ]);

        $marked = (new SyncScheduledPostsAction)->handle();

        $this->assertSame(0, $marked['posts']);
        $post->refresh();
        $this->assertSame('scheduled', $post->status);
        $this->assertSame('FAILED', $post->postsyncer['groups'][0]['status']);
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

        $this->assertSame(0, $marked['posts']);
        $this->assertSame(0, $marked['videos']);
        $post->refresh();
        $this->assertSame('scheduled', $post->status);
        $this->assertSame('SCHEDULED', $post->postsyncer['groups'][0]['status']);
    }

    public function test_it_marks_a_video_posted_when_every_group_is_published(): void
    {
        $workspace = Workspace::factory()->create(['settings' => []]);
        $this->configureWorkspace($workspace);

        $video = Video::factory()->for($workspace)->create([
            'status' => 'scheduled',
            'postsyncer' => [
                'groups' => [
                    [
                        'post_id' => '140052',
                        'status' => 'SCHEDULED',
                        'scheduled_at' => '2026-08-26T09:00:00+06:00',
                        'platforms' => ['facebook'],
                        'language' => 'bangla',
                    ],
                    [
                        'post_id' => '140053',
                        'status' => 'SCHEDULED',
                        'scheduled_at' => '2026-08-26T09:00:00+06:00',
                        'platforms' => ['youtube'],
                        'language' => 'english',
                    ],
                ],
            ],
        ]);

        Http::fake([
            'postsyncer.com/api/v1/posts/140052' => Http::response([
                'id' => 140052,
                'status' => 'PUBLISHED',
                'published_at' => '2026-08-26T09:01:00+06:00',
            ], 200),
            'postsyncer.com/api/v1/posts/140053' => Http::response([
                'id' => 140053,
                'status' => 'published',
                'published_at' => '2026-08-26T09:01:00+06:00',
            ], 200),
        ]);

        $marked = (new SyncScheduledPostsAction)->handle();

        $this->assertSame(0, $marked['posts']);
        $this->assertSame(1, $marked['videos']);
        $video->refresh();
        $this->assertSame('posted', $video->status);
        $this->assertSame('PUBLISHED', $video->postsyncer['groups'][0]['status']);
        $this->assertSame('2026-08-26T09:01:00+06:00', $video->postsyncer['groups'][0]['published_at']);
        $this->assertSame('PUBLISHED', $video->postsyncer['groups'][1]['status']);
    }

    public function test_it_leaves_a_video_scheduled_when_a_group_is_still_queued(): void
    {
        $workspace = Workspace::factory()->create(['settings' => []]);
        $this->configureWorkspace($workspace);

        $video = Video::factory()->for($workspace)->create([
            'status' => 'scheduled',
            'postsyncer' => [
                'groups' => [
                    [
                        'post_id' => '140052',
                        'status' => 'SCHEDULED',
                        'platforms' => ['facebook'],
                        'language' => 'bangla',
                    ],
                    [
                        'post_id' => '140053',
                        'status' => 'SCHEDULED',
                        'platforms' => ['youtube'],
                        'language' => 'english',
                    ],
                ],
            ],
        ]);

        Http::fake([
            'postsyncer.com/api/v1/posts/140052' => Http::response([
                'id' => 140052,
                'status' => 'PUBLISHED',
            ], 200),
            'postsyncer.com/api/v1/posts/140053' => Http::response([
                'id' => 140053,
                'status' => 'SCHEDULED',
            ], 200),
        ]);

        $marked = (new SyncScheduledPostsAction)->handle();

        $this->assertSame(0, $marked['posts']);
        $this->assertSame(0, $marked['videos']);
        $video->refresh();
        $this->assertSame('scheduled', $video->status);
        $this->assertSame('PUBLISHED', $video->postsyncer['groups'][0]['status']);
        $this->assertSame('SCHEDULED', $video->postsyncer['groups'][1]['status']);
    }

    public function test_it_skips_a_video_group_when_the_live_lookup_fails(): void
    {
        $workspace = Workspace::factory()->create(['settings' => []]);
        $this->configureWorkspace($workspace);

        $video = Video::factory()->for($workspace)->create([
            'status' => 'scheduled',
            'postsyncer' => [
                'groups' => [
                    [
                        'post_id' => '140052',
                        'status' => 'SCHEDULED',
                        'platforms' => ['facebook'],
                        'language' => 'bangla',
                    ],
                ],
            ],
        ]);

        Http::fake([
            'postsyncer.com/api/v1/posts/140052' => Http::response(['message' => 'Not found'], 404),
        ]);

        $marked = (new SyncScheduledPostsAction)->handle();

        $this->assertSame(0, $marked['posts']);
        $this->assertSame(0, $marked['videos']);
        $video->refresh();
        $this->assertSame('scheduled', $video->status);
        $this->assertSame('SCHEDULED', $video->postsyncer['groups'][0]['status']);
    }
}
