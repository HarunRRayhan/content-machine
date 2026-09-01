<?php

namespace Tests\Unit\Actions\Postsyncer;

use App\Actions\Postsyncer\EnqueuePostPublishAction;
use App\Actions\Postsyncer\PublishPostAction;
use App\Jobs\PublishPostJob;
use App\Models\Post;
use App\Models\TelegramBotConfig;
use App\Models\TelegramPostRequest;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EnqueuePostPublishActionTest extends TestCase
{
    use RefreshDatabase;

    private function configureWorkspace(Workspace $workspace): void
    {
        PostsyncerConfig::write($workspace, [
            'publish_enabled' => true,
            'api_key' => 'test-api-key',
            'languages' => [
                'english' => ['workspace_id' => '853', 'platforms' => []],
                'bangla' => ['workspace_id' => '15211', 'platforms' => []],
            ],
        ]);
    }

    public function test_pending_posts_are_rejected_before_a_job_is_queued(): void
    {
        Queue::fake();
        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $post = Post::factory()->for($workspace)->create([
            'approval_state' => 'pending',
        ]);

        try {
            (new EnqueuePostPublishAction)->handle($post, $workspace);
            $this->fail('A pending post should not be publishable.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('needs human approval', $exception->errors()['publish'][0]);
        }

        Queue::assertNothingPushed();
    }

    public function test_an_approved_post_is_queued_for_publishing(): void
    {
        Queue::fake();
        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $post = Post::factory()->for($workspace)->create([
            'approval_state' => 'approved',
            'publish_state' => 'idle',
        ]);

        $updated = (new EnqueuePostPublishAction)->handle($post, $workspace, [
            'when' => '2026-09-03T09:00:00+06:00',
            'confirm_ask' => true,
        ]);

        $this->assertSame('queued', $updated->publish_state);
        Queue::assertPushed(PublishPostJob::class, fn (PublishPostJob $job): bool => $job->post->is($post)
            && $job->options['when'] === '2026-09-03T09:00:00+06:00'
            && $job->options['confirm_ask'] === true);
    }

    public function test_a_second_enqueue_cannot_queue_another_job(): void
    {
        Queue::fake();
        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $post = Post::factory()->for($workspace)->create([
            'approval_state' => 'approved',
            'publish_state' => 'idle',
        ]);

        (new EnqueuePostPublishAction)->handle($post, $workspace);

        try {
            // Use the original stale model to prove the action reloads before
            // checking state, as two HTTP requests would do.
            (new EnqueuePostPublishAction)->handle($post, $workspace);
            $this->fail('A second publish should not be queued.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('already in progress', $exception->errors()['publish'][0]);
        }

        Queue::assertPushed(PublishPostJob::class, 1);
    }

    public function test_a_failed_telegram_request_is_reactivated_for_a_retry(): void
    {
        Queue::fake();
        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $post = Post::factory()->for($workspace)->create([
            'approval_state' => 'approved',
            'publish_state' => 'failed',
        ]);
        $config = TelegramBotConfig::factory()->for($workspace)->create();
        $request = TelegramPostRequest::factory()->for($workspace)->create([
            'telegram_bot_config_id' => $config->id,
            'post_id' => $post->id,
            'state' => TelegramPostRequest::FAILED,
            'error_message' => 'previous failure',
        ]);

        (new EnqueuePostPublishAction)->handle($post, $workspace);

        $this->assertSame(TelegramPostRequest::APPROVED, $request->refresh()->state);
        $this->assertNull($request->error_message);
    }

    public function test_enqueue_persists_the_publish_plan_before_dispatching_the_job(): void
    {
        Queue::fake();
        $workspace = Workspace::factory()->create();
        PostsyncerConfig::write($workspace, [
            'publish_enabled' => true,
            'api_key' => 'test-api-key',
            'languages' => [
                'english' => [
                    'workspace_id' => '853',
                    'platforms' => ['facebook' => ['account_id' => 100]],
                ],
            ],
            'post_types' => [
                'platforms' => ['facebook' => ['text' => 'on']],
                'overrides' => [],
            ],
        ]);
        $post = Post::factory()->for($workspace)->create([
            'language' => 'en',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Freeze this plan'],
        ]);

        $updated = (new EnqueuePostPublishAction)->handle($post, $workspace);

        $this->assertIsString($updated->publish_progress['plan_hash'] ?? null);
        $this->assertNotSame('', $updated->publish_progress['plan_hash']);
        $this->assertCount(1, $updated->publish_progress['planned_groups']);
        $this->assertSame('queued', $updated->publish_progress['state']);
    }

    public function test_worker_refuses_to_publish_if_the_enqueued_plan_changed(): void
    {
        Queue::fake();
        $workspace = Workspace::factory()->create();
        PostsyncerConfig::write($workspace, [
            'publish_enabled' => true,
            'api_key' => 'test-api-key',
            'languages' => [
                'english' => [
                    'workspace_id' => '853',
                    'platforms' => ['facebook' => ['account_id' => 100]],
                ],
            ],
            'post_types' => [
                'platforms' => ['facebook' => ['text' => 'on']],
                'overrides' => [],
            ],
        ]);
        $post = Post::factory()->for($workspace)->create([
            'language' => 'en',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Original plan'],
        ]);

        (new EnqueuePostPublishAction)->handle($post, $workspace);
        $job = Queue::pushed(PublishPostJob::class)->first();
        $this->assertInstanceOf(PublishPostJob::class, $job);

        $post->forceFill(['captions' => ['facebook' => 'Changed after enqueue']])->save();
        Http::fake();

        $job->handle(app(PublishPostAction::class));

        $post->refresh();
        $this->assertSame('failed', $post->publish_state);
        $this->assertStringContainsString('publish plan changed', strtolower((string) $post->publish_error));
        Http::assertNothingSent();
    }
}
