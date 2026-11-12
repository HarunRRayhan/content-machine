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

    public function test_it_does_not_ask_postsyncer_about_a_group_that_is_not_due_yet(): void
    {
        $workspace = Workspace::factory()->create(['settings' => []]);
        $this->configureWorkspace($workspace);

        $post = Post::factory()->for($workspace)->create([
            'status' => 'scheduled',
            'postsyncer' => [
                'groups' => [
                    [
                        'post_id' => '140001',
                        'status' => 'SCHEDULED',
                        'scheduled_at' => now()->addDay()->toIso8601String(),
                        'synced_at' => now()->subHours(3)->toIso8601String(),
                        'platforms' => ['facebook'],
                        'language' => 'english',
                    ],
                    [
                        // No offset: read in the workspace's timezone (Asia/Dhaka by default), so due an hour ago.
                        'post_id' => '140003',
                        'status' => 'SCHEDULED',
                        'scheduled_at' => now('Asia/Dhaka')->subHour()->format('Y-m-d H:i'),
                        'platforms' => ['bluesky'],
                        'language' => 'english',
                    ],
                    [
                        'post_id' => '140002',
                        'status' => 'SCHEDULED',
                        'scheduled_at' => now()->subMinute()->toIso8601String(),
                        'platforms' => ['twitter'],
                        'language' => 'english',
                    ],
                ],
            ],
        ]);

        Http::fake([
            'postsyncer.com/api/v1/posts/140002' => Http::response(['id' => 140002, 'status' => 'PUBLISHED'], 200),
            'postsyncer.com/api/v1/posts/140003' => Http::response(['id' => 140003, 'status' => 'SCHEDULED'], 200),
            '*' => Http::response([], 500),
        ]);

        $marked = (new SyncScheduledPostsAction)->handle();

        $this->assertSame(0, $marked['posts']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/posts/140001'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/posts/140002'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/posts/140003'));
        $post->refresh();
        $this->assertSame('scheduled', $post->status);
        $this->assertSame('SCHEDULED', $post->postsyncer['groups'][0]['status']);
        $this->assertSame('PUBLISHED', $post->postsyncer['groups'][2]['status']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function group(int $postId, array $overrides = []): array
    {
        return array_merge([
            'post_id' => (string) $postId,
            'status' => 'SCHEDULED',
            'scheduled_at' => now()->subMinutes(10)->toIso8601String(),
            'platforms' => ['facebook'],
            'language' => 'english',
        ], $overrides);
    }

    public function test_a_far_future_group_is_checked_once_a_day_and_a_published_group_never(): void
    {
        $workspace = Workspace::factory()->create(['settings' => []]);
        $this->configureWorkspace($workspace);
        $nextWeek = now()->addWeek()->toIso8601String();
        $moved = now()->addDays(3)->toIso8601String();

        $post = Post::factory()->for($workspace)->create([
            'status' => 'scheduled',
            'postsyncer' => ['groups' => [
                $this->group(1, ['scheduled_at' => $nextWeek]),
                $this->group(2, ['scheduled_at' => $nextWeek, 'synced_at' => now()->subHours(25)->toIso8601String()]),
                $this->group(3, ['scheduled_at' => $nextWeek, 'synced_at' => now()->subHours(23)->toIso8601String()]),
                $this->group(4, ['status' => 'PUBLISHED']),
                $this->group(5, ['status' => 'FAILED', 'synced_at' => now()->subHour()->toIso8601String()]),
            ]],
        ]);

        Http::fake(['*' => Http::response(['status' => 'SCHEDULED', 'scheduled_at' => $moved], 200)]);

        $result = (new SyncScheduledPostsAction)->handle();

        $this->assertSame(2, $result['requests']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/posts/1'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/posts/2'));
        Http::assertSentCount(2);
        // The daily look is what notices a time moved inside PostSyncer.
        $this->assertSame($moved, $post->refresh()->postsyncer['groups'][0]['scheduled_at']);

        (new SyncScheduledPostsAction)->handle();

        Http::assertSentCount(2);
    }

    public function test_a_run_stays_within_its_request_budget_and_reaches_every_group_over_time(): void
    {
        $workspace = Workspace::factory()->create(['settings' => []]);
        $this->configureWorkspace($workspace);
        $budget = SyncScheduledPostsAction::MAX_REQUESTS_PER_RUN;

        // More due groups and more daily checks than one run may ask about.
        Post::factory()->for($workspace)->create([
            'status' => 'scheduled',
            'postsyncer' => ['groups' => array_map(fn (int $id): array => $this->group($id), range(1, $budget + 5))],
        ]);
        Video::factory()->for($workspace)->create([
            'status' => 'scheduled',
            'postsyncer' => ['groups' => array_map(
                fn (int $id): array => $this->group($id, ['scheduled_at' => now()->addWeek()->toIso8601String()]),
                range(101, 110),
            )],
        ]);

        Http::fake(['*' => Http::response(['status' => 'SCHEDULED'], 200)]);

        $first = (new SyncScheduledPostsAction)->handle();

        $this->assertSame($budget, $first['requests']);
        $this->assertFalse($first['rate_limited']);
        Http::assertSentCount($budget);
        $asked = fn (): array => Http::recorded()->map(fn (array $pair): int => (int) basename($pair[0]->url()))->all();
        // Due groups come first, but daily checks keep a share so they cannot be starved.
        $this->assertCount(15, array_filter($asked(), fn (int $id): bool => $id <= 100));
        $this->assertCount(5, array_filter($asked(), fn (int $id): bool => $id > 100));

        $this->travel(5)->minutes();
        (new SyncScheduledPostsAction)->handle();
        $this->travel(5)->minutes();
        (new SyncScheduledPostsAction)->handle();

        Http::assertSentCount($budget * 3);
        // Least recently checked first: three runs reached every one of the 35 groups.
        $this->assertCount($budget + 15, array_unique($asked()));
    }

    public function test_a_rate_limit_ends_the_run_without_marking_anything_failed(): void
    {
        $workspace = Workspace::factory()->create(['settings' => []]);
        $this->configureWorkspace($workspace);

        $post = Post::factory()->for($workspace)->create([
            'status' => 'scheduled',
            'postsyncer' => ['groups' => [$this->group(1), $this->group(2), $this->group(3)]],
        ]);

        Http::fake([
            'postsyncer.com/api/v1/posts/1' => Http::response(['status' => 'PUBLISHED'], 200),
            '*' => Http::response(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '30']),
        ]);

        $result = (new SyncScheduledPostsAction)->handle();

        $this->assertTrue($result['rate_limited']);
        $this->assertSame(1, $result['requests']);
        // One answered lookup, one 429, and the third group is not asked at all.
        Http::assertSentCount(2);
        $post->refresh();
        $this->assertSame('scheduled', $post->status);
        $this->assertNull($post->publish_error);
        $this->assertSame(['PUBLISHED', 'SCHEDULED', 'SCHEDULED'], array_column($post->postsyncer['groups'], 'status'));
        // The unanswered groups stay first in line for the next run.
        $this->assertArrayNotHasKey('synced_at', $post->postsyncer['groups'][1]);
    }

    public function test_a_run_stops_when_the_remaining_allowance_drops_below_the_floor(): void
    {
        $workspace = Workspace::factory()->create(['settings' => []]);
        $this->configureWorkspace($workspace);

        Post::factory()->for($workspace)->create([
            'status' => 'scheduled',
            'postsyncer' => ['groups' => [$this->group(1), $this->group(2), $this->group(3)]],
        ]);

        Http::fake(['*' => Http::sequence()
            ->push(['status' => 'SCHEDULED'], 200, ['X-RateLimit-Remaining' => (string) SyncScheduledPostsAction::RATE_LIMIT_FLOOR])
            ->push(['status' => 'SCHEDULED'], 200, ['X-RateLimit-Remaining' => (string) (SyncScheduledPostsAction::RATE_LIMIT_FLOOR - 1)])
            ->push(['status' => 'SCHEDULED'], 200)]);

        $result = (new SyncScheduledPostsAction)->handle();

        $this->assertTrue($result['rate_limited']);
        Http::assertSentCount(2);
    }

    public function test_a_record_whose_groups_are_already_terminal_is_marked_without_a_lookup(): void
    {
        $workspace = Workspace::factory()->create(['settings' => []]);
        $this->configureWorkspace($workspace);

        $post = Post::factory()->for($workspace)->create([
            'status' => 'scheduled',
            'postsyncer' => ['groups' => [$this->group(1, ['status' => 'PUBLISHED'])]],
        ]);
        Http::fake();

        $result = (new SyncScheduledPostsAction)->handle();

        $this->assertSame(1, $result['posts']);
        $this->assertSame('posted', $post->refresh()->status);
        Http::assertNothingSent();
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

    public function test_operator_confirmed_failed_group_stays_raw_but_counts_as_delivered(): void
    {
        $workspace = Workspace::factory()->create(['settings' => []]);
        $this->configureWorkspace($workspace);

        $post = Post::factory()->for($workspace)->create([
            'status' => 'scheduled',
            'postsyncer' => [
                'groups' => [[
                    'post_id' => '132732',
                    'status' => 'FAILED',
                    'operator_confirmed' => true,
                    'remote_status' => 'FAILED',
                    'platforms' => ['instagram'],
                    'language' => 'english',
                ]],
            ],
        ]);

        Http::fake([
            'postsyncer.com/api/v1/posts/132732' => Http::response([
                'id' => 132732,
                'status' => 'FAILED',
            ], 200),
        ]);

        $marked = (new SyncScheduledPostsAction)->handle();

        $this->assertSame(1, $marked['posts']);
        $post->refresh();
        $this->assertSame('posted', $post->status);
        $this->assertSame('FAILED', $post->postsyncer['groups'][0]['status']);
        $this->assertSame('FAILED', $post->postsyncer['groups'][0]['remote_status']);
        $this->assertTrue($post->postsyncer['groups'][0]['operator_confirmed']);
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
