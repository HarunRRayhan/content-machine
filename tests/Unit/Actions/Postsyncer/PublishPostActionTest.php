<?php

namespace Tests\Unit\Actions\Postsyncer;

use App\Actions\Postsyncer\PublishPostAction;
use App\Models\Post;
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

        $this->action->handle($post, ['confirm_ask' => false]);

        $post->refresh();
        $this->assertSame('succeeded', $post->publish_state);
        $this->assertNull($post->publish_error);
        $this->assertSame('posted', $post->status);
        $this->assertSame([
            'groups' => [[
                'post_id' => '42',
                'status' => 'PUBLISHED',
                'scheduled_at' => null,
                'platforms' => ['facebook', 'instagram'],
                'language' => 'bangla',
            ]],
        ], $post->postsyncer);

        Http::assertSentCount(2);
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

        $this->action->handle($post, [
            'confirm_ask' => false,
            'when' => '2026-08-26T09:12:00+06:00',
        ]);

        $post->refresh();
        $this->assertSame('succeeded', $post->publish_state);
        $this->assertSame('scheduled', $post->status);
        $this->assertSame('SCHEDULED', $post->postsyncer['groups'][0]['status']);
        $this->assertSame('2026-08-26T09:12:00+06:00', $post->postsyncer['groups'][0]['scheduled_at']);

        Http::assertSent(fn ($request) => $request->url() === 'https://postsyncer.com/api/v1/posts'
            && $request['schedule_type'] === 'schedule'
            && $request['schedule_for']['date'] === '2026-08-26');
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

        $this->action->handle($post, ['confirm_ask' => false]);

        $post->refresh();
        $this->assertSame('failed', $post->publish_state);
        $this->assertStringContainsString('PostSyncer API error 422', $post->publish_error);
        $this->assertSame('ready', $post->status);
        $this->assertNull($post->postsyncer);
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
}
