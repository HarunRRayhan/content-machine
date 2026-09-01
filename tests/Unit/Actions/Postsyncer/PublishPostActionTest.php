<?php

namespace Tests\Unit\Actions\Postsyncer;

use App\Actions\Postsyncer\PublishPostAction;
use App\Models\Post;
use App\Models\TelegramBotConfig;
use App\Models\TelegramPostRequest;
use App\Models\Workspace;
use App\Support\Postsyncer\MediaUrlResolver;
use App\Support\Postsyncer\PostPublishPlanner;
use App\Support\Postsyncer\PostsyncerConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
