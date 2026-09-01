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
            ], 200),
            'postsyncer.com/api/v1/posts' => Http::response([
                'id' => 42,
                'status' => 'published',
            ], 201),
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
        $this->assertEquals([
            'groups' => [[
                'post_id' => '42',
                'status' => 'PUBLISHED',
                'scheduled_at' => null,
                'platforms' => ['facebook', 'instagram'],
                'language' => 'bangla',
            ]],
        ], $post->postsyncer);
        $this->assertSame(TelegramPostRequest::PUBLISHED, $request->refresh()->state);

        Http::assertSentCount(2);
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

    public function test_create_response_without_a_publishable_status_is_not_marked_successful(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/posts' => Http::response(['id' => 42], 201),
        ]);

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $post = Post::factory()->for($workspace)->create([
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Status must be verified'],
        ]);

        $this->action->handle($post, ['confirm_ask' => false]);

        $post->refresh();
        $this->assertSame('failed', $post->publish_state);
        $this->assertSame('uncertain', $post->publish_progress['state']);
        $this->assertStringContainsString('no valid lifecycle status', (string) $post->publish_error);
        $this->assertNull($post->postsyncer);
    }

    public function test_create_response_with_the_wrong_schedule_is_not_marked_successful(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/posts' => Http::response([
                'id' => 42,
                'status' => 'scheduled',
                'scheduled_at' => '2026-08-26T10:12:00+06:00',
            ], 201),
        ]);

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $post = Post::factory()->for($workspace)->create([
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Schedule must be verified'],
        ]);

        $this->action->handle($post, [
            'when' => '2026-08-26T09:12:00+06:00',
            'confirm_ask' => false,
        ]);

        $post->refresh();
        $this->assertSame('failed', $post->publish_state);
        $this->assertSame('uncertain', $post->publish_progress['state']);
        $this->assertStringContainsString('does not match the requested schedule', (string) $post->publish_error);
        $this->assertNull($post->postsyncer);
    }

    public function test_a_failed_later_group_resumes_without_recreating_completed_groups(): void
    {
        $workspace = Workspace::factory()->create();
        PostsyncerConfig::write($workspace, [
            'api_key' => 'test-api-key',
            'languages' => [
                'bangla' => [
                    'workspace_id' => '15211',
                    'platforms' => [
                        'facebook' => ['account_id' => 100],
                        'linkedin' => ['account_id' => 102],
                    ],
                ],
            ],
            'post_types' => [
                'platforms' => [
                    'facebook' => ['text' => 'on'],
                    'linkedin' => ['text' => 'on'],
                ],
                'overrides' => [],
            ],
        ]);

        $createCalls = 0;
        $retrying = false;
        Http::fake(function ($request) use (&$createCalls, &$retrying) {
            if ($request->url() !== 'https://postsyncer.com/api/v1/posts') {
                return Http::response(['message' => 'Unexpected request'], 500);
            }

            $createCalls++;

            if (! $retrying && $createCalls === 2) {
                return Http::response(['message' => 'invalid account'], 422);
            }

            return Http::response([
                'id' => $createCalls === 1 ? 10 : 11,
                'status' => 'scheduled',
                'scheduled_at' => '2026-08-26T09:12:00+06:00',
            ], 201);
        });

        $post = Post::factory()->for($workspace)->create([
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook', 'linkedin'],
            'captions' => [
                'facebook' => 'Facebook caption',
                'linkedin' => 'LinkedIn caption',
            ],
        ]);
        $options = [
            'confirm_ask' => false,
            'when' => '2026-08-26T09:12:00+06:00',
        ];

        $this->action->handle($post, $options);

        $post->refresh();
        $this->assertSame('failed', $post->publish_state);
        $this->assertSame('failed', $post->publish_progress['state']);
        $this->assertCount(1, $post->publish_progress['completed_groups']);
        $this->assertSame('10', $post->publish_progress['completed_groups'][0]['post_id']);

        $retrying = true;
        $this->action->handle($post, $options);

        $post->refresh();
        $this->assertSame('succeeded', $post->publish_state);
        $this->assertSame('scheduled', $post->status);
        $this->assertSame(['10', '11'], array_column($post->postsyncer['groups'], 'post_id'));
        $this->assertSame(3, $createCalls);
    }

    public function test_an_uncertain_create_is_checkpointed_and_not_replayed(): void
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

        try {
            $this->action->handle($post, $options);
        } catch (PostsyncerException) {
            // Unknown create outcomes must escape so the queue can retry.
        }

        $post->refresh();
        $this->assertSame('failed', $post->publish_state);
        $this->assertSame('uncertain', $post->publish_progress['state']);
        $this->assertNotNull($post->publish_progress['current']);
        $this->assertNotEmpty($post->publish_progress['current']['idempotency_key']);

        Http::fake([
            'postsyncer.com/api/v1/posts' => Http::response(['id' => 99, 'status' => 'scheduled'], 201),
        ]);
        $this->action->handle($post, $options);

        $this->assertSame('failed', $post->refresh()->publish_state);
        Http::assertNothingSent();
    }

    public function test_an_uncertain_create_can_be_reconciled_and_resumed(): void
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
            'captions' => ['facebook' => 'Scheduled caption'],
        ]);
        $options = [
            'when' => '2026-08-26T09:12:00+06:00',
            'confirm_ask' => false,
        ];

        try {
            $this->action->handle($post, $options);
        } catch (PostsyncerException) {
            // Unknown create outcomes must escape so the queue can retry.
        }
        $post->refresh();

        Http::fake([
            'postsyncer.com/api/v1/posts/99' => Http::response([
                'id' => 99,
                'workspace_id' => 15211,
                'content' => [[
                    'text' => 'Scheduled caption',
                    'media' => [],
                ]],
                'platforms' => [['platform' => 'facebook']],
                'status' => 'SCHEDULED',
                'scheduled_at' => '2026-08-26T09:12:00+06:00',
            ], 200),
        ]);

        $this->action->reconcile($post, 99);

        $post->refresh();
        $this->assertSame('failed', $post->publish_state);
        $this->assertNull($post->publish_progress['current']);
        $this->assertSame('99', $post->publish_progress['completed_groups'][0]['post_id']);

        Http::fake([
            'postsyncer.com/api/v1/posts' => Http::response(['message' => 'must not create again'], 500),
        ]);
        $this->action->handle($post, $options);

        $post->refresh();
        $this->assertSame('succeeded', $post->publish_state);
        $this->assertSame('scheduled', $post->status);
        $this->assertSame('99', $post->postsyncer['groups'][0]['post_id']);
        Http::assertNothingSent();
    }

    public function test_create_sends_the_persisted_group_idempotency_key(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/posts' => Http::response([
                'id' => 99,
                'status' => 'published',
            ], 201),
        ]);

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $post = Post::factory()->for($workspace)->create([
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Idempotent caption'],
        ]);

        $this->action->handle($post, ['confirm_ask' => false]);

        $key = $post->refresh()->publish_progress['completed_groups'][0]['group_key'] ?? null;
        $this->assertIsString($key);
        Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key')
            && $request->header('Idempotency-Key')[0] === hash(
                'sha256',
                $post->publish_progress['operation_id'].'|0|'.$key,
            ));
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

    public function test_empty_media_upload_response_fails_instead_of_publishing_text(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/media/upload/url' => Http::response([
                'media' => [],
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
        $this->assertStringContainsString('no media ids', (string) $post->publish_error);
        $this->assertSame('ready', $post->status);
        Http::assertNotSent(fn ($request) => $request->url() === 'https://postsyncer.com/api/v1/posts');
    }

    public function test_partial_media_upload_response_fails_instead_of_publishing_with_missing_images(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/media/upload/url' => Http::response([
                'media' => [['id' => 915]],
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
            'captions' => ['facebook' => 'Do not drop the second image'],
            'image_drive_urls' => [
                'https://drive.google.com/file/d/abc/view',
                'https://drive.google.com/file/d/def/view',
            ],
        ]);

        $this->action->handle($post, ['confirm_ask' => false]);

        $post->refresh();
        $this->assertSame('failed', $post->publish_state);
        $this->assertStringContainsString('incomplete media response', (string) $post->publish_error);
        Http::assertNotSent(fn ($request) => $request->url() === 'https://postsyncer.com/api/v1/posts');
    }

    public function test_media_upload_is_checkpointed_before_and_after_the_upload(): void
    {
        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $post = Post::factory()->for($workspace)->create([
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Media caption'],
            'image_drive_urls' => ['https://drive.google.com/file/d/abc/view'],
        ]);
        $phases = [];

        Http::fake(function ($request) use ($post, &$phases) {
            if ($request->url() === 'https://postsyncer.com/api/v1/media/upload/url') {
                $phases[] = $post->refresh()->publish_progress['current']['phase'] ?? null;

                return Http::response(['media' => [['id' => 915]]], 200);
            }

            $post->refresh();
            $phases[] = $post->publish_progress['current']['phase'] ?? null;

            return Http::response(['id' => 42, 'status' => 'published'], 201);
        });

        $this->action->handle($post, ['confirm_ask' => false]);

        $this->assertSame(['uploading', 'creating'], $phases);
        $this->assertSame('succeeded', $post->refresh()->publish_state);
        $this->assertNull($post->publish_lease_id);
    }

    public function test_an_expired_worker_gets_a_new_publish_lease_before_retrying(): void
    {
        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $oldLeaseId = '72d9c4a1-58b0-4be7-95c0-a1d2227d2f22';
        $post = Post::factory()->for($workspace)->create([
            'status' => 'ready',
            'publish_state' => 'running',
            'publish_claimed_at' => now()->subSeconds(PublishPostJob::TIMEOUT_SECONDS + 1),
            'publish_lease_id' => $oldLeaseId,
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Lease caption'],
            'publish_progress' => [
                'version' => 1,
                'operation_id' => 'operation-123',
                'options' => ['confirm_ask' => false, 'when' => null],
                'plan_hash' => null,
                'planned_groups' => [],
                'completed_groups' => [],
                'current' => null,
                'state' => 'queued',
            ],
        ]);
        $seenLeaseId = null;

        Http::fake(function ($request) use ($post, &$seenLeaseId) {
            $seenLeaseId = $post->refresh()->publish_lease_id;

            return Http::response(['id' => 43, 'status' => 'published'], 201);
        });

        $this->action->handle($post, ['confirm_ask' => false], 'operation-123', $oldLeaseId);

        $this->assertIsString($seenLeaseId);
        $this->assertNotSame($oldLeaseId, $seenLeaseId);
        $this->assertSame('succeeded', $post->refresh()->publish_state);
    }

    public function test_a_retryable_media_upload_failure_can_be_retried_by_the_same_queue_job(): void
    {
        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        PostsyncerConfig::write($workspace, [
            'publish_enabled' => true,
            'default_language' => 'bangla',
        ]);
        $post = Post::factory()->for($workspace)->create([
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Retry media'],
            'image_drive_urls' => ['https://drive.google.com/file/d/abc/view'],
        ]);
        $attempt = 0;

        Http::fake(function ($request) use (&$attempt) {
            if ($request->url() === 'https://postsyncer.com/api/v1/media/upload/url') {
                $attempt++;

                return $attempt === 1
                    ? Http::response(['message' => 'temporary failure'], 503)
                    : Http::response(['media' => [['id' => 916]]], 200);
            }

            return Http::response(['id' => 44, 'status' => 'published'], 201);
        });

        Queue::fake();
        (new EnqueuePostPublishAction($this->action))->handle($post, $workspace, [
            'confirm_ask' => false,
        ]);
        $job = Queue::pushed(PublishPostJob::class)->first();
        $this->assertInstanceOf(PublishPostJob::class, $job);

        try {
            $job->handle($this->action);
            $this->fail('The first media upload should be retryable.');
        } catch (PostsyncerException $exception) {
            $this->assertTrue($exception->retryable);
        }

        $this->assertSame('failed', $post->refresh()->publish_state);
        $this->assertSame('uploading', $post->publish_progress['current']['phase']);
        $this->assertNull($post->publish_lease_id);

        $job->handle($this->action);

        $post->refresh();
        $this->assertSame('succeeded', $post->publish_state);
        $this->assertSame(2, $attempt);
        Http::assertSentCount(3);
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
            ], 200),
            'postsyncer.com/api/v1/posts' => Http::response([
                'id' => 42,
                'status' => 'published',
            ], 201),
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
