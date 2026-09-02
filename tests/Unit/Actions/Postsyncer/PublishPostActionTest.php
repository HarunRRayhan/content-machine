<?php

namespace Tests\Unit\Actions\Postsyncer;

use App\Actions\Postsyncer\EnqueuePostPublishAction;
use App\Actions\Postsyncer\PublishPostAction;
use App\Jobs\PublishPostJob;
use App\Models\Post;
use App\Models\TelegramBotConfig;
use App\Models\TelegramPostRequest;
use App\Models\Workspace;
use App\Support\Postsyncer\MediaUrlResolver;
use App\Support\Postsyncer\PostPublishPlanner;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Postsyncer\PostsyncerException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PublishPostActionTest extends TestCase
{
    use RefreshDatabase;

    private PublishPostAction $action;

    /**
     * @return array<string, mixed>
     */
    private function samplePostTypes(): array
    {
        return [
            'platforms' => [
                'facebook' => ['text' => 'on', 'photo' => 'on'],
                'instagram' => ['photo' => 'on'],
                'linkedin' => ['text' => 'on', 'photo' => 'on'],
            ],
            'overrides' => [],
        ];
    }

    private function configureWorkspace(Workspace $workspace): void
    {
        PostsyncerConfig::write($workspace, [
            'api_key' => 'test-api-key',
            'languages' => [
                'bangla' => [
                    'workspace_id' => '15211',
                    'platforms' => [
                        'facebook' => ['account_id' => 100, 'handle' => '@harun'],
                        'instagram' => ['account_id' => 101, 'handle' => '@harun.ig'],
                        'linkedin' => ['account_id' => 102, 'handle' => '@harun.li'],
                    ],
                ],
            ],
            'post_types' => $this->samplePostTypes(),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = new PublishPostAction(new PostPublishPlanner(new MediaUrlResolver));
    }

    public function test_publish_now_sets_succeeded_status_posted_and_groups(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/media/upload/url' => Http::response([
                'media' => [['id' => 915]],
                'count_stored' => 1,
            ], 200),
            'postsyncer.com/api/v1/posts' => Http::response([
                'id' => 42,
                'status' => 'published',
            ], 201),
            'postsyncer.com/api/v1/posts/42' => Http::response([
                'id' => 42,
                'workspace_id' => 15211,
                'content' => [[
                    'text' => 'FB caption',
                    'media' => [['id' => 915]],
                ]],
                'platforms' => [
                    ['platform' => 'facebook', 'account_id' => 100, 'settings' => [
                        'post_type' => 'POST', 'caption' => 'FB caption',
                    ]],
                    ['platform' => 'instagram', 'account_id' => 101, 'settings' => [
                        'post_type' => 'POST', 'caption' => 'IG caption',
                    ]],
                ],
                'status' => 'PUBLISHED',
            ], 200),
        ]);

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);

        $post = Post::factory()->for($workspace)->create([
            'status' => 'draft',
            'publish_state' => 'queued',
            'language' => 'bn',
            'platforms' => ['facebook', 'instagram'],
            'captions' => [
                'main' => [
                    'facebook' => ['caption' => 'FB caption'],
                    'instagram' => ['caption' => 'IG caption'],
                ],
            ],
            'image_drive_urls' => ['https://drive.google.com/file/d/abc/view'],
        ]);
        $config = TelegramBotConfig::factory()->for($workspace)->create();
        $request = TelegramPostRequest::factory()->for($workspace)->create([
            'telegram_bot_config_id' => $config->id,
            'post_id' => $post->id,
            'state' => TelegramPostRequest::APPROVED,
        ]);

        $this->action->handle($post, ['confirm_ask' => false]);

        $post->refresh();
        $this->assertSame('succeeded', $post->publish_state);
        $this->assertNull($post->publish_error);
        $this->assertSame('posted', $post->status);
        $group = $post->postsyncer['groups'][0];
        $this->assertSame('42', $group['post_id']);
        $this->assertSame('PUBLISHED', $group['status']);
        $this->assertNull($group['scheduled_at']);
        $this->assertSame(['facebook', 'instagram'], $group['platforms']);
        $this->assertSame('bangla', $group['language']);
        $progress = $post->publish_progress;
        $this->assertIsArray($progress);
        $this->assertSame('succeeded', $progress['state']);
        $this->assertCount(1, $progress['completed_groups']);
        $this->assertIsString($progress['completed_groups'][0]['group_key']);

        Http::assertSentCount(3);
    }

    public function test_create_polls_an_async_canonical_response_before_checkpointing(): void
    {
        $lookups = 0;
        Http::fake(function ($request) use (&$lookups) {
            if ($request->url() === 'https://postsyncer.com/api/v1/posts') {
                return Http::response(['id' => 42, 'status' => 'queued'], 201);
            }

            if ($request->url() === 'https://postsyncer.com/api/v1/posts/42') {
                $lookups++;

                if ($lookups === 1) {
                    return Http::response(['id' => 42, 'status' => 'PENDING'], 200);
                }

                return Http::response(['data' => [
                    'id' => 42,
                    'workspace_id' => 15211,
                    'content' => [['text' => 'Caption', 'media' => []]],
                    'platforms' => [['platform' => 'facebook', 'account_id' => 100, 'settings' => [
                        'post_type' => 'POST', 'caption' => 'Caption',
                    ]]],
                    'status' => 'PUBLISHED',
                ]], 200);
            }

            return Http::response(['message' => 'Unexpected request'], 500);
        });

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $post = Post::factory()->for($workspace)->create([
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Caption'],
        ]);

        $this->action->handle($post, ['confirm_ask' => false]);

        $post->refresh();
        $this->assertSame('succeeded', $post->publish_state, (string) $post->publish_error);
        $this->assertSame(2, $lookups);
        Http::assertSentCount(3);
    }

    public function test_canonical_connection_failure_is_retried_without_recreating(): void
    {
        $lookups = 0;
        Http::fake(function ($request) use (&$lookups) {
            if ($request->url() === 'https://postsyncer.com/api/v1/posts') {
                return Http::response(['id' => 43, 'status' => 'published'], 201);
            }

            if ($request->url() === 'https://postsyncer.com/api/v1/posts/43') {
                $lookups++;

                if ($lookups < 3) {
                    throw new ConnectionException('connection refused');
                }

                return Http::response([
                    'id' => 43,
                    'workspace_id' => 15211,
                    'content' => [['text' => 'Caption', 'media' => []]],
                    'platforms' => [['platform' => 'facebook', 'account_id' => 100, 'settings' => [
                        'post_type' => 'POST', 'caption' => 'Caption',
                    ]]],
                    'status' => 'PUBLISHED',
                ], 200);
            }

            return Http::response(['message' => 'Unexpected request'], 500);
        });

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $post = Post::factory()->for($workspace)->create([
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Caption'],
        ]);

        $this->action->handle($post, ['confirm_ask' => false]);

        $post->refresh();
        $this->assertSame('succeeded', $post->publish_state);
        $this->assertSame(3, $lookups);
        $this->assertCount(1, Http::recorded(
            fn ($request): bool => $request->url() === 'https://postsyncer.com/api/v1/posts',
        ));
    }

    public function test_canonical_payload_mismatch_becomes_uncertain_without_recreating(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/posts' => Http::response(['id' => 44, 'status' => 'published'], 201),
            'postsyncer.com/api/v1/posts/44' => Http::response([
                'id' => 44,
                'workspace_id' => 15211,
                'content' => [['text' => 'Wrong caption', 'media' => []]],
                'platforms' => [['platform' => 'facebook', 'account_id' => 100, 'settings' => [
                    'post_type' => 'POST', 'caption' => 'Caption',
                ]]],
                'status' => 'PUBLISHED',
            ], 200),
        ]);

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $post = Post::factory()->for($workspace)->create([
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Caption'],
        ]);

        $this->action->handle($post, ['confirm_ask' => false]);

        $post->refresh();
        $this->assertSame('failed', $post->publish_state);
        $this->assertStringContainsString('outcome is uncertain', (string) $post->publish_error);
        $this->assertSame('uncertain', $post->publish_progress['state']);
        $this->assertNotNull($post->publish_progress['current']);
        $this->assertCount(1, Http::recorded(
            fn ($request): bool => $request->url() === 'https://postsyncer.com/api/v1/posts',
        ));
    }

    public function test_a_pending_post_is_not_published_if_approval_changes_after_enqueue(): void
    {
        Http::fake();

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $post = Post::factory()->for($workspace)->create([
            'status' => 'draft',
            'publish_state' => 'queued',
            'approval_state' => 'pending',
        ]);

        $this->action->handle($post, []);

        $post->refresh();
        $this->assertSame('failed', $post->publish_state);
        $this->assertSame('This post needs human approval before it can be published.', $post->publish_error);
        Http::assertNothingSent();
    }

    public function test_schedule_sets_status_scheduled(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/posts' => Http::response([
                'id' => 99,
                'status' => 'scheduled',
                'scheduled_at' => '2026-08-26T09:12:00+06:00',
            ], 201),
            'postsyncer.com/api/v1/posts/99' => Http::response([
                'id' => 99,
                'workspace_id' => 15211,
                'content' => [[
                    'text' => 'Scheduled caption',
                    'media' => [],
                ]],
                'platforms' => [['platform' => 'facebook', 'account_id' => 100, 'settings' => [
                    'post_type' => 'POST', 'caption' => 'Scheduled caption',
                ]]],
                'status' => 'SCHEDULED',
                'scheduled_at' => '2026-08-26T09:12:00+06:00',
            ], 200),
        ]);

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);

        $post = Post::factory()->for($workspace)->create([
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Scheduled caption'],
        ]);
        $config = TelegramBotConfig::factory()->for($workspace)->create();
        $request = TelegramPostRequest::factory()->for($workspace)->create([
            'telegram_bot_config_id' => $config->id,
            'post_id' => $post->id,
            'state' => TelegramPostRequest::APPROVED,
        ]);

        $this->action->handle($post, [
            'confirm_ask' => false,
            'when' => '2026-08-26T09:12:00+06:00',
        ]);

        $post->refresh();
        $this->assertSame('succeeded', $post->publish_state);
        $this->assertSame('scheduled', $post->status);
        $this->assertSame('SCHEDULED', $post->postsyncer['groups'][0]['status']);
        $this->assertSame('2026-08-26T09:12:00+06:00', $post->postsyncer['groups'][0]['scheduled_at']);
        $this->assertSame(TelegramPostRequest::PUBLISHED, $request->refresh()->state);

        Http::assertSent(fn ($request) => $request->url() === 'https://postsyncer.com/api/v1/posts'
            && $request['schedule_type'] === 'schedule'
            && $request['schedule_for']['date'] === '2026-08-26'
            && $request['schedule_for']['timezone'] === 'Asia/Dhaka');
    }

    public function test_retry_resumes_after_a_later_group_fails_without_recreating_earlier_groups(): void
    {
        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);

        $post = Post::factory()->for($workspace)->create([
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook', 'linkedin'],
            'captions' => [
                'main' => [
                    'facebook' => [
                        'caption' => 'FB caption',
                        'images' => ['https://example.com/fb.png'],
                    ],
                    'linkedin' => [
                        'caption' => 'LinkedIn caption',
                        'images' => [],
                    ],
                ],
            ],
        ]);

        $createCalls = 0;
        $retrying = false;
        Http::fake(function ($request) use (&$createCalls, &$retrying) {
            if (str_ends_with($request->url(), '/media/upload/url')) {
                return $retrying
                    ? Http::response(['media' => [['id' => 915]], 'count_stored' => 1], 200)
                    : Http::response(['media' => [], 'count_stored' => 0], 200);
            }

            if ($request->url() === 'https://postsyncer.com/api/v1/posts/10') {
                return Http::response([
                    'id' => 10,
                    'workspace_id' => 15211,
                    'content' => [['text' => 'LinkedIn caption', 'media' => []]],
                    'platforms' => [['platform' => 'linkedin', 'account_id' => 102, 'settings' => []]],
                    'status' => 'SCHEDULED',
                    'scheduled_at' => '2026-08-26T09:12:00+06:00',
                ], 200);
            }

            if ($request->url() === 'https://postsyncer.com/api/v1/posts/11') {
                return Http::response([
                    'id' => 11,
                    'workspace_id' => 15211,
                    'content' => [['text' => 'FB caption', 'media' => [['id' => 915]]]],
                    'platforms' => [['platform' => 'facebook', 'account_id' => 100, 'settings' => [
                        'post_type' => 'POST', 'caption' => 'FB caption',
                    ]]],
                    'status' => 'SCHEDULED',
                    'scheduled_at' => '2026-08-26T09:12:00+06:00',
                ], 200);
            }

            if ($request->url() !== 'https://postsyncer.com/api/v1/posts') {
                return Http::response(['message' => 'Unexpected request'], 500);
            }

            $createCalls++;

            return Http::response([
                'id' => $createCalls === 1 ? 10 : 11,
                'status' => 'scheduled',
                'scheduled_at' => '2026-08-26T09:12:00+06:00',
            ], 201);
        });

        $options = [
            'confirm_ask' => false,
            'when' => '2026-08-26T09:12:00+06:00',
        ];

        $this->action->handle($post, $options);

        $post->refresh();
        $this->assertSame('failed', $post->publish_state);
        $this->assertNull($post->postsyncer);
        $this->assertCount(1, $post->publish_progress['completed_groups']);
        $this->assertSame('10', $post->publish_progress['completed_groups'][0]['post_id']);
        $this->assertIsString($post->publish_progress['completed_groups'][0]['group_key']);

        $retrying = true;

        $this->action->handle($post, $options);

        $post->refresh();
        $this->assertSame('succeeded', $post->publish_state);
        $this->assertSame('scheduled', $post->status);
        $this->assertSame('succeeded', $post->publish_progress['state']);
        $this->assertCount(2, $post->postsyncer['groups']);
        $this->assertSame(['10', '11'], array_column($post->postsyncer['groups'], 'post_id'));
        $this->assertArrayNotHasKey('group_key', $post->postsyncer['groups'][0]);
        Http::assertSentCount(6);
    }

    public function test_duplicate_delivery_after_success_does_not_publish_again_or_mark_failure(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/posts' => Http::response([
                'id' => 42,
                'status' => 'published',
            ], 201),
            'postsyncer.com/api/v1/posts/42' => Http::response([
                'id' => 42,
                'workspace_id' => 15211,
                'content' => [['text' => 'Publish once', 'media' => []]],
                'platforms' => [['platform' => 'facebook', 'account_id' => 100, 'settings' => [
                    'post_type' => 'POST', 'caption' => 'Publish once',
                ]]],
                'status' => 'PUBLISHED',
            ], 200),
        ]);

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);

        $post = Post::factory()->for($workspace)->create([
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Publish once'],
        ]);

        $this->action->handle($post, ['confirm_ask' => false]);

        Http::fake([
            'postsyncer.com/api/v1/posts' => Http::response(['message' => 'must not publish'], 500),
        ]);

        $this->action->handle($post, ['confirm_ask' => false]);

        $post->refresh();
        $this->assertSame('succeeded', $post->publish_state);
        $this->assertSame('posted', $post->status);
        Http::assertNothingSent();
    }

    public function test_stale_run_does_not_write_after_the_token_rotates_during_create(): void
    {
        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);

        $post = Post::factory()->for($workspace)->create([
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Caption'],
        ]);

        $createCalled = false;
        Http::fake(function ($request) use ($post, &$createCalled) {
            if ($request->url() === 'https://postsyncer.com/api/v1/posts') {
                $createCalled = true;
                Post::query()->whereKey($post->id)->update([
                    'publish_state' => 'queued',
                    'publish_progress' => [
                        'run_token' => 'new-run',
                        'state' => 'queued',
                    ],
                ]);

                return Http::response(['id' => 42, 'status' => 'published'], 201);
            }

            return Http::response(['message' => 'Unexpected request'], 500);
        });

        $this->action->handle($post, ['confirm_ask' => false], 'old-run');

        $post->refresh();
        $this->assertSame('queued', $post->publish_state);
        $this->assertNull($post->postsyncer);
        $this->assertSame('new-run', $post->publish_progress['run_token']);
        $this->assertTrue($createCalled);
    }

    public function test_unknown_create_outcome_is_not_replayed(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/posts' => Http::response(['message' => 'gateway timeout'], 500),
        ]);

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);

        $post = Post::factory()->for($workspace)->create([
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Publish once'],
        ]);
        $options = ['when' => '2026-08-26T09:12:00+06:00'];

        $this->action->handle($post, $options);

        $post->refresh();
        $this->assertSame('failed', $post->publish_state);
        $this->assertStringContainsString('outcome is uncertain', (string) $post->publish_error);
        $this->assertSame('uncertain', $post->publish_progress['state']);
        $this->assertNotNull($post->publish_progress['current']);
        $this->assertNotEmpty($post->publish_progress['current']['idempotency_key']);
        $this->assertSame([], $post->publish_progress['current']['media_ids']);
        $this->assertNull($post->postsyncer);

        Http::fake([
            'postsyncer.com/api/v1/posts' => Http::response([
                'id' => 99,
                'status' => 'scheduled',
            ], 201),
        ]);

        $this->action->handle($post, $options);

        $post->refresh();
        $this->assertSame('failed', $post->publish_state);
        $this->assertSame('uncertain', $post->publish_progress['state']);
        Http::assertNothingSent();
    }

    public function test_complete_progress_can_finalize_without_another_external_call(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/posts' => Http::response([
                'id' => 42,
                'status' => 'scheduled',
                'scheduled_at' => '2026-08-26T09:12:00+06:00',
            ], 201),
            'postsyncer.com/api/v1/posts/42' => Http::response([
                'id' => 42,
                'workspace_id' => 15211,
                'content' => [['text' => 'Scheduled caption', 'media' => []]],
                'platforms' => [['platform' => 'facebook', 'account_id' => 100, 'settings' => [
                    'post_type' => 'POST', 'caption' => 'Scheduled caption',
                ]]],
                'status' => 'SCHEDULED',
                'scheduled_at' => '2026-08-26T09:12:00+06:00',
            ], 200),
        ]);

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);

        $post = Post::factory()->for($workspace)->create([
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Scheduled caption'],
        ]);
        $options = [
            'when' => '2026-08-26T09:12:00+06:00',
            'confirm_ask' => false,
        ];

        $this->action->handle($post, $options);
        $post->refresh();
        $progress = $post->publish_progress;
        $post->forceFill([
            'status' => 'ready',
            'publish_state' => 'failed',
            'publish_error' => 'simulated finalization interruption',
            'postsyncer' => null,
            'publish_progress' => [
                ...$progress,
                'state' => 'failed',
            ],
        ])->save();

        Http::fake([
            'postsyncer.com/api/v1/posts' => Http::response(['message' => 'must not publish'], 500),
        ]);

        $this->action->handle($post, $options);

        $post->refresh();
        $this->assertSame('succeeded', $post->publish_state);
        $this->assertSame('scheduled', $post->status);
        Http::assertNothingSent();
    }

    public function test_uncertain_create_can_be_verified_and_resumed(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/posts' => Http::response([
                'message' => 'gateway timeout',
            ], 500),
        ]);

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);

        $post = Post::factory()->for($workspace)->create([
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Scheduled caption'],
        ]);
        $options = [
            'when' => '2026-08-26T09:12:00+06:00',
            'confirm_ask' => false,
        ];

        $this->action->handle($post, $options);
        $post->refresh();

        Http::fake([
            'postsyncer.com/api/v1/posts/99' => Http::response([
                'id' => 99,
                'workspace_id' => 15211,
                'content' => [[
                    'text' => 'Scheduled caption',
                    'media' => [],
                ]],
                'platforms' => [['platform' => 'facebook', 'account_id' => 100, 'settings' => [
                    'post_type' => 'POST', 'caption' => 'Scheduled caption',
                ]]],
                'status' => 'SCHEDULED',
                'scheduled_at' => '2026-08-26T09:12:00+06:00',
            ], 200),
        ]);

        $this->action->reconcile($post, 99);

        $post->refresh();
        $this->assertNull($post->publish_progress['current']);
        $this->assertSame('failed', $post->publish_progress['state']);
        $this->assertSame('99', $post->publish_progress['completed_groups'][0]['post_id']);

        Http::fake([
            'postsyncer.com/api/v1/posts' => Http::response([
                'message' => 'must not create again',
            ], 500),
        ]);

        $this->action->handle($post, $options);

        $post->refresh();
        $this->assertSame('succeeded', $post->publish_state);
        $this->assertSame('scheduled', $post->status);
        Http::assertNothingSent();
    }

    public function test_reconciliation_replaces_a_duplicate_completed_checkpoint(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/posts' => Http::response([
                'message' => 'gateway timeout',
            ], 500),
        ]);

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $post = Post::factory()->for($workspace)->create([
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Caption'],
        ]);
        $options = ['confirm_ask' => false];

        $this->action->handle($post, $options);
        $post->refresh();

        $progress = $post->publish_progress;
        $progress['completed_groups'][] = [
            'index' => 0,
            'group_key' => $progress['current']['group_key'],
            'post_id' => '98',
            'status' => 'PUBLISHED',
            'scheduled_at' => null,
            'platforms' => ['facebook'],
            'language' => 'bangla',
        ];
        $post->forceFill(['publish_progress' => $progress])->save();

        Http::fake([
            'postsyncer.com/api/v1/posts/99' => Http::response([
                'id' => 99,
                'workspace_id' => 15211,
                'content' => [['text' => 'Caption', 'media' => []]],
                'platforms' => [['platform' => 'facebook', 'account_id' => 100, 'settings' => [
                    'post_type' => 'POST', 'caption' => 'Caption',
                ]]],
                'status' => 'PUBLISHED',
            ], 200),
        ]);

        $this->action->reconcile($post, 99);

        $post->refresh();
        $this->assertCount(1, $post->publish_progress['completed_groups']);
        $this->assertSame('99', $post->publish_progress['completed_groups'][0]['post_id']);

        Http::fake([
            'postsyncer.com/api/v1/posts' => Http::response([
                'message' => 'must not create again',
            ], 500),
        ]);
        $this->action->handle($post, $options);

        $post->refresh();
        $this->assertSame('succeeded', $post->publish_state);
        Http::assertNothingSent();
    }

    public function test_reconciliation_rejects_a_failed_remote_post(): void
    {
        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);

        $post = Post::factory()->for($workspace)->create([
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Scheduled caption'],
        ]);
        $options = [
            'when' => '2026-08-26T09:12:00+06:00',
            'confirm_ask' => false,
        ];

        Http::fake([
            'postsyncer.com/api/v1/posts' => Http::response(['message' => 'gateway timeout'], 500),
        ]);
        $this->action->handle($post, $options);

        Http::fake([
            'postsyncer.com/api/v1/posts/99' => Http::response([
                'id' => 99,
                'workspace_id' => 15211,
                'content' => [[
                    'text' => 'Scheduled caption',
                    'media' => [],
                ]],
                'platforms' => [['platform' => 'facebook', 'account_id' => 100, 'settings' => [
                    'post_type' => 'POST', 'caption' => 'Scheduled caption',
                ]]],
                'status' => 'FAILED',
                'scheduled_at' => '2026-08-26T09:12:00+06:00',
            ], 200),
        ]);

        $this->expectException(PostsyncerException::class);
        $this->expectExceptionMessage('not in a publishable state');
        $this->action->reconcile($post, 99);
    }

    public function test_confirm_failed_reconciliation_requires_the_explicit_flag(): void
    {
        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);

        $post = Post::factory()->for($workspace)->create([
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Scheduled caption'],
        ]);
        $options = [
            'when' => '2026-08-26T09:12:00+06:00',
            'confirm_ask' => false,
        ];

        Http::fake([
            'postsyncer.com/api/v1/posts' => Http::response(['message' => 'gateway timeout'], 500),
        ]);
        $this->action->handle($post, $options);

        Http::fake([
            'postsyncer.com/api/v1/posts/99' => Http::response([
                'id' => 99,
                'workspace_id' => 15211,
                'content' => [['text' => 'Scheduled caption', 'media' => []]],
                'platforms' => [['platform' => 'facebook', 'account_id' => 100, 'settings' => [
                    'post_type' => 'POST', 'caption' => 'Scheduled caption',
                ]]],
                'status' => 'FAILED',
                'scheduled_at' => '2026-08-26T09:12:00+06:00',
            ], 200),
        ]);

        try {
            $this->action->reconcile($post, 99);
            $this->fail('Reconciliation should require explicit confirmation for FAILED.');
        } catch (PostsyncerException $exception) {
            $this->assertStringContainsString('not in a publishable state', $exception->getMessage());
        }

        $this->action->reconcile($post, 99, true);

        $post->refresh();
        $group = $post->publish_progress['completed_groups'][0];
        $this->assertSame('FAILED', $group['status']);
        $this->assertSame('FAILED', $group['remote_status']);
        $this->assertTrue($group['operator_confirmed']);

        Http::fake([
            'postsyncer.com/api/v1/posts' => Http::response(['message' => 'must not create again'], 500),
        ]);
        $this->action->handle($post, $options);

        $post->refresh();
        $this->assertSame('succeeded', $post->publish_state);
        $this->assertSame('scheduled', $post->status);
        $this->assertSame('FAILED', $post->postsyncer['groups'][0]['status']);
        Http::assertNothingSent();
    }

    public function test_transient_media_failure_is_rethrown_for_queue_retry(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/media/upload/url' => Http::response([
                'message' => 'temporary outage',
            ], 503),
        ]);

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);

        $post = Post::factory()->for($workspace)->create([
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Caption'],
            'image_drive_urls' => ['https://drive.google.com/file/d/abc/view'],
        ]);

        $this->expectException(PostsyncerException::class);
        $this->action->handle($post, ['confirm_ask' => false]);
    }

    public function test_failure_leaves_pipeline_status_and_sets_publish_error(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/media/upload/url' => Http::response([
                'message' => 'Invalid workspace',
            ], 422),
        ]);

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);

        $post = Post::factory()->for($workspace)->create([
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook', 'instagram'],
            'captions' => [
                'main' => [
                    'facebook' => ['caption' => 'FB caption'],
                    'instagram' => ['caption' => 'IG caption'],
                ],
            ],
            'image_drive_urls' => ['https://drive.google.com/file/d/abc/view'],
        ]);
        $config = TelegramBotConfig::factory()->for($workspace)->create();
        $request = TelegramPostRequest::factory()->for($workspace)->create([
            'telegram_bot_config_id' => $config->id,
            'post_id' => $post->id,
            'state' => TelegramPostRequest::APPROVED,
        ]);

        $this->action->handle($post, ['confirm_ask' => false]);

        $post->refresh();
        $this->assertSame('failed', $post->publish_state);
        $this->assertStringContainsString('PostSyncer API error 422', $post->publish_error);
        $this->assertSame('ready', $post->status);
        $this->assertNull($post->postsyncer);
        $this->assertSame(TelegramPostRequest::FAILED, $request->refresh()->state);
        $this->assertStringContainsString('PostSyncer API error 422', (string) $request->error_message);
    }

    public function test_create_validation_error_is_not_marked_as_uncertain_or_retried(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/posts' => Http::response([
                'message' => 'Invalid account configuration',
            ], 422),
        ]);

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);

        $post = Post::factory()->for($workspace)->create([
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Caption'],
        ]);

        $this->action->handle($post, ['confirm_ask' => false]);

        $post->refresh();
        $this->assertSame('failed', $post->publish_state);
        $this->assertStringContainsString('Invalid account configuration', (string) $post->publish_error);
        $this->assertSame('failed', $post->publish_progress['state']);
        $this->assertNull($post->publish_progress['current']);
    }

    public function test_failed_create_can_retry_after_content_plan_changes(): void
    {
        $createCalls = 0;
        Http::fake(function ($request) use (&$createCalls) {
            if ($request->url() === 'https://postsyncer.com/api/v1/posts') {
                $createCalls++;

                return $createCalls === 1
                    ? Http::response(['message' => 'invalid caption'], 422)
                    : Http::response(['id' => 42, 'status' => 'published'], 201);
            }

            if ($request->url() === 'https://postsyncer.com/api/v1/posts/42') {
                return Http::response([
                    'id' => 42,
                    'workspace_id' => 15211,
                    'content' => [['text' => 'Changed caption', 'media' => []]],
                    'platforms' => [['platform' => 'facebook', 'account_id' => 100, 'settings' => [
                        'post_type' => 'POST', 'caption' => 'Changed caption',
                    ]]],
                    'status' => 'PUBLISHED',
                ], 200);
            }

            return Http::response(['message' => 'Unexpected request'], 500);
        });

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $post = Post::factory()->for($workspace)->create([
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Original caption'],
        ]);
        $options = ['confirm_ask' => false];

        $this->action->handle($post, $options);
        $post->update(['captions' => ['facebook' => 'Changed caption']]);
        $this->action->handle($post, $options);

        $post->refresh();
        $this->assertSame(2, $createCalls);
        $this->assertSame('succeeded', $post->publish_state);
        $this->assertSame('42', $post->postsyncer['groups'][0]['post_id']);
    }

    public function test_create_response_without_a_status_is_not_finalized(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/posts' => Http::response([
                'id' => 42,
            ], 201),
        ]);

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);

        $post = Post::factory()->for($workspace)->create([
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Caption'],
        ]);

        $this->action->handle($post, ['confirm_ask' => false]);

        $post->refresh();
        $this->assertSame('failed', $post->publish_state);
        $this->assertStringContainsString('outcome is uncertain', (string) $post->publish_error);
        $this->assertSame('uncertain', $post->publish_progress['state']);
        $this->assertNotNull($post->publish_progress['current']);
        $this->assertNull($post->postsyncer);
    }

    public function test_failed_create_response_is_not_finalized(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/posts' => Http::response([
                'id' => 42,
                'status' => 'FAILED',
            ], 201),
            'postsyncer.com/api/v1/posts/42' => Http::response([
                'id' => 42,
                'workspace_id' => 15211,
                'content' => [['text' => 'Caption', 'media' => []]],
                'platforms' => [['platform' => 'facebook', 'account_id' => 100, 'settings' => [
                    'post_type' => 'POST', 'caption' => 'Caption',
                ]]],
                'status' => 'FAILED',
            ], 200),
        ]);

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);

        $post = Post::factory()->for($workspace)->create([
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Caption'],
        ]);

        $this->action->handle($post, ['confirm_ask' => false]);

        $post->refresh();
        $this->assertSame('failed', $post->publish_state);
        $this->assertStringContainsString('not in a publishable state', (string) $post->publish_error);
        $this->assertSame('uncertain', $post->publish_progress['state']);
        $this->assertNotNull($post->publish_progress['current']);
        $this->assertNull($post->postsyncer);
    }

    public function test_scheduled_create_response_must_include_the_requested_schedule(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/posts' => Http::response([
                'id' => 42,
                'status' => 'SCHEDULED',
            ], 201),
            'postsyncer.com/api/v1/posts/42' => Http::response([
                'id' => 42,
                'workspace_id' => 15211,
                'content' => [['text' => 'Caption', 'media' => []]],
                'platforms' => [['platform' => 'facebook', 'account_id' => 100, 'settings' => [
                    'post_type' => 'POST', 'caption' => 'Caption',
                ]]],
                'status' => 'SCHEDULED',
            ], 200),
        ]);

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);

        $post = Post::factory()->for($workspace)->create([
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Caption'],
        ]);

        $this->action->handle($post, [
            'confirm_ask' => false,
            'when' => '2026-08-26T09:12:00+06:00',
        ]);

        $post->refresh();
        $this->assertSame('failed', $post->publish_state);
        $this->assertStringContainsString('no verifiable schedule', (string) $post->publish_error);
        $this->assertSame('uncertain', $post->publish_progress['state']);
        $this->assertNotNull($post->publish_progress['current']);
        $this->assertNull($post->postsyncer);
    }

    public function test_post_changes_during_publish_are_not_finalized_as_success(): void
    {
        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);

        $post = Post::factory()->for($workspace)->create([
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Original caption'],
        ]);
        $changed = false;

        Http::fake(function ($request) use ($post, &$changed) {
            if ($request->url() === 'https://postsyncer.com/api/v1/posts' && ! $changed) {
                Post::query()->whereKey($post->id)->update([
                    'captions' => ['facebook' => 'Changed caption'],
                ]);
                $changed = true;

                return Http::response(['id' => 42, 'status' => 'published'], 201);
            }

            if ($request->url() === 'https://postsyncer.com/api/v1/posts/42') {
                return Http::response([
                    'id' => 42,
                    'workspace_id' => 15211,
                    'content' => [['text' => 'Original caption', 'media' => []]],
                    'platforms' => [['platform' => 'facebook', 'account_id' => 100, 'settings' => [
                        'post_type' => 'POST', 'caption' => 'Original caption',
                    ]]],
                    'status' => 'PUBLISHED',
                ], 200);
            }

            return Http::response(['message' => 'Unexpected request'], 500);
        });

        $this->action->handle($post, ['confirm_ask' => false]);

        $post->refresh();
        $this->assertSame('failed', $post->publish_state);
        $this->assertStringContainsString('changed while', (string) $post->publish_error);
        $this->assertNull($post->postsyncer);
    }

    public function test_plan_drift_can_be_recovered_from_the_stored_payload_snapshot(): void
    {
        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);

        $post = Post::factory()->for($workspace)->create([
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Original caption'],
        ]);
        $changed = false;

        Http::fake(function ($request) use ($post, &$changed) {
            if ($request->url() === 'https://postsyncer.com/api/v1/posts' && ! $changed) {
                Post::query()->whereKey($post->id)->update([
                    'captions' => ['facebook' => 'Changed caption'],
                ]);
                $changed = true;

                return Http::response(['id' => 42, 'status' => 'published'], 201);
            }

            if ($request->url() === 'https://postsyncer.com/api/v1/posts/42') {
                return Http::response([
                    'id' => 42,
                    'workspace_id' => 15211,
                    'content' => [['text' => 'Original caption', 'media' => []]],
                    'platforms' => [['platform' => 'facebook', 'account_id' => 100, 'settings' => [
                        'post_type' => 'POST', 'caption' => 'Original caption',
                    ]]],
                    'status' => 'PUBLISHED',
                ], 200);
            }

            return Http::response(['message' => 'Unexpected request'], 500);
        });

        $this->action->handle($post, ['confirm_ask' => false]);
        $post->refresh();

        $this->assertSame('failed', $post->publish_state);
        $this->assertNull($post->postsyncer);
        $this->assertCount(1, $post->publish_progress['completed_groups']);

        $this->action->recoverPlanDrift($post);

        $post->refresh();
        $this->assertSame('succeeded', $post->publish_state);
        $this->assertSame('posted', $post->status);
        $this->assertTrue($post->publish_progress['plan_drift_recovered']);
        $this->assertSame('42', $post->postsyncer['groups'][0]['post_id']);
        $this->assertSame(2, count(Http::recorded(fn ($request): bool => $request->url() === 'https://postsyncer.com/api/v1/posts/42')));
    }

    public function test_empty_media_upload_response_fails_instead_of_publishing_text(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/media/upload/url' => Http::response([
                'media' => [],
                'count_stored' => 0,
            ], 200),
            'postsyncer.com/api/v1/posts' => Http::response([
                'id' => 42,
                'status' => 'published',
            ], 201),
        ]);

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);

        $post = Post::factory()->for($workspace)->create([
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => [
                'main' => [
                    'facebook' => ['caption' => 'FB caption'],
                ],
            ],
            'image_drive_urls' => ['https://drive.google.com/file/d/abc/view'],
        ]);

        $this->action->handle($post, ['confirm_ask' => false]);

        $post->refresh();
        $this->assertSame('failed', $post->publish_state);
        $this->assertStringContainsString('incomplete media upload response', (string) $post->publish_error);
        $this->assertSame('ready', $post->status);
        Http::assertNotSent(fn ($request) => $request->url() === 'https://postsyncer.com/api/v1/posts');
    }

    public function test_missing_account_mapping_is_retriable_without_uploading_media(): void
    {
        $ready = false;
        Http::fake(function ($request) use (&$ready) {
            if (! $ready) {
                return Http::response(['message' => 'Unexpected request'], 500);
            }

            if ($request->url() === 'https://postsyncer.com/api/v1/media/upload/url') {
                return Http::response([
                    'media' => [['id' => 915]],
                    'count_stored' => 1,
                ], 200);
            }

            if ($request->url() === 'https://postsyncer.com/api/v1/posts') {
                return Http::response(['id' => 42, 'status' => 'published'], 201);
            }

            if ($request->url() === 'https://postsyncer.com/api/v1/posts/42') {
                return Http::response([
                    'id' => 42,
                    'workspace_id' => 15211,
                    'content' => [['text' => 'Caption', 'media' => [['id' => 915]]]],
                    'platforms' => [['platform' => 'facebook', 'account_id' => 100, 'settings' => [
                        'post_type' => 'POST', 'caption' => 'Caption',
                    ]]],
                    'status' => 'PUBLISHED',
                ], 200);
            }

            return Http::response(['message' => 'Unexpected request'], 500);
        });

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        PostsyncerConfig::write($workspace, [
            'languages' => [
                'bangla' => [
                    'platforms' => [
                        'facebook' => ['account_id' => null],
                    ],
                ],
            ],
        ]);
        $post = Post::factory()->for($workspace)->create([
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Caption'],
            'image_drive_urls' => ['https://drive.google.com/file/d/image/view'],
        ]);

        $this->action->handle($post, ['confirm_ask' => false]);

        $post->refresh();
        $this->assertSame('failed', $post->publish_state);
        $this->assertStringContainsString('No account id mapped', (string) $post->publish_error);
        $this->assertSame('failed', $post->publish_progress['state']);
        $this->assertNull($post->publish_progress['current']);
        $this->assertTrue($post->canRetryPublish());
        Http::assertNothingSent();

        PostsyncerConfig::write($workspace, [
            'languages' => [
                'bangla' => [
                    'platforms' => [
                        'facebook' => ['account_id' => 100],
                    ],
                ],
            ],
        ]);
        $ready = true;

        $this->action->handle($post, ['confirm_ask' => false]);

        $post->refresh();
        $this->assertSame('succeeded', $post->publish_state);
        $this->assertSame('42', $post->postsyncer['groups'][0]['post_id']);
    }

    public function test_uncertain_media_upload_can_be_reconciled_without_uploading_again(): void
    {
        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $post = Post::factory()->for($workspace)->create([
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Caption'],
            'image_drive_urls' => ['https://drive.google.com/file/d/abc/view'],
        ]);
        $uploadCalls = 0;

        Http::fake(function ($request) use (&$uploadCalls) {
            if (str_ends_with($request->url(), '/media/upload/url')) {
                $uploadCalls++;

                return $uploadCalls === 1
                    ? Http::response(['message' => 'temporary outage'], 503)
                    : Http::response(['message' => 'upload must not be retried'], 500);
            }

            if ($request->url() === 'https://postsyncer.com/api/v1/posts') {
                return Http::response(['id' => 42, 'status' => 'published'], 201);
            }

            return Http::response([
                'id' => 42,
                'workspace_id' => 15211,
                'content' => [['text' => 'Caption', 'media' => [['id' => 915]]]],
                'platforms' => [['platform' => 'facebook', 'account_id' => 100, 'settings' => [
                    'post_type' => 'POST', 'caption' => 'Caption',
                ]]],
                'status' => 'PUBLISHED',
            ], 200);
        });

        try {
            $this->action->handle($post, ['confirm_ask' => false]);
        } catch (PostsyncerException $exception) {
            $this->assertTrue($exception->retryable);
        }

        $post->refresh();
        $this->assertSame('uncertain', $post->publish_progress['state']);
        $this->assertSame('uploading', $post->publish_progress['current']['phase']);

        $this->action->reconcileMedia($post, [915]);

        $post->refresh();
        $this->assertSame('failed', $post->publish_state);
        $this->assertSame('failed', $post->publish_progress['state']);
        $this->assertSame('retryable', $post->publish_progress['current']['phase']);
        $this->assertSame(['915'], $post->publish_progress['current']['media_ids']);

        $this->action->handle($post, ['confirm_ask' => false]);

        $post->refresh();
        $this->assertSame('succeeded', $post->publish_state);
        $this->assertSame('42', $post->postsyncer['groups'][0]['post_id']);
        $this->assertSame(1, $uploadCalls);
    }

    public function test_sets_running_before_api_calls(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/posts' => Http::response(['id' => 1, 'status' => 'published'], 201),
        ]);

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);

        $post = Post::factory()->for($workspace)->create([
            'status' => 'draft',
            'publish_state' => 'queued',
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Hello'],
        ]);

        $seenRunning = false;
        Http::fake(function ($request) use ($post, &$seenRunning) {
            $post->refresh();
            if ($post->publish_state === 'running') {
                $seenRunning = true;
            }

            return Http::response(['id' => 1, 'status' => 'published'], 201);
        });

        $this->action->handle($post, ['confirm_ask' => false]);

        $this->assertTrue($seenRunning);
    }

    public function test_facebook_first_comment_is_sent_as_second_content_item(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/media/upload/url' => Http::response([
                'media' => [['id' => 915]],
                'count_stored' => 1,
            ], 200),
            'postsyncer.com/api/v1/posts' => Http::response([
                'id' => 42,
                'status' => 'published',
            ], 201),
            'postsyncer.com/api/v1/posts/42' => Http::response([
                'id' => 42,
                'workspace_id' => 15211,
                'content' => [
                    ['text' => 'FB caption', 'media' => [['id' => 915]]],
                    [
                        'text' => 'SemiAnalysis numbers here',
                        'media' => [],
                        'is_first_comment' => true,
                        'first_comment_delay' => 1,
                    ],
                ],
                'platforms' => [['platform' => 'facebook', 'account_id' => 100, 'settings' => [
                    'post_type' => 'POST', 'caption' => 'FB caption',
                ]]],
                'status' => 'PUBLISHED',
            ], 200),
        ]);

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);

        $post = Post::factory()->for($workspace)->create([
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => [
                'main' => [
                    'facebook' => [
                        'caption' => 'FB caption',
                        'first_comment' => 'SemiAnalysis numbers here',
                    ],
                ],
            ],
            'image_drive_urls' => ['https://drive.google.com/file/d/abc/view'],
        ]);

        $this->action->handle($post, ['confirm_ask' => false]);

        $post->refresh();
        $this->assertSame('succeeded', $post->publish_state);

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://postsyncer.com/api/v1/posts') {
                return false;
            }

            $content = $request['content'] ?? [];

            return count($content) === 2
                && ($content[0]['text'] ?? null) === 'FB caption'
                && ($content[0]['media'] ?? null) === [915]
                && ($content[1]['text'] ?? null) === 'SemiAnalysis numbers here'
                && ($content[1]['is_first_comment'] ?? null) === true
                && ($content[1]['first_comment_delay'] ?? null) === 1;
        });
    }

    public function test_threads_group_does_not_get_is_first_comment_content_item(): void
    {
        $workspace = Workspace::factory()->create();
        PostsyncerConfig::write($workspace, [
            'api_key' => 'test-api-key',
            'languages' => [
                'bangla' => [
                    'workspace_id' => '15211',
                    'platforms' => [
                        'threads' => ['account_id' => 200, 'handle' => '@harun'],
                    ],
                ],
            ],
            'post_types' => [
                'platforms' => [
                    'threads' => ['text' => 'on', 'photo' => 'on'],
                ],
                'overrides' => [],
            ],
        ]);

        Http::fake([
            'postsyncer.com/api/v1/media/upload/url' => Http::response([
                'media' => [['id' => 915]],
                'count_stored' => 1,
            ], 200),
            'postsyncer.com/api/v1/posts' => Http::response([
                'id' => 42,
                'status' => 'published',
            ], 201),
            'postsyncer.com/api/v1/posts/42' => Http::response([
                'id' => 42,
                'workspace_id' => 15211,
                'content' => [['text' => 'Threads caption', 'media' => [['id' => 915]]]],
                'platforms' => [['platform' => 'threads', 'account_id' => 200, 'settings' => [
                    'title' => 'Threads caption',
                ]]],
                'status' => 'PUBLISHED',
            ], 200),
        ]);

        $post = Post::factory()->for($workspace)->create([
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['threads'],
            'captions' => [
                'main' => [
                    'threads' => [
                        'caption' => 'Threads caption',
                        'first_comment' => 'should not become a first comment',
                    ],
                ],
            ],
            'image_drive_urls' => ['https://drive.google.com/file/d/abc/view'],
        ]);

        $this->action->handle($post, ['confirm_ask' => false]);

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://postsyncer.com/api/v1/posts') {
                return false;
            }

            $content = $request['content'] ?? [];

            return count($content) === 1
                && ! isset($content[0]['is_first_comment']);
        });
    }
}
