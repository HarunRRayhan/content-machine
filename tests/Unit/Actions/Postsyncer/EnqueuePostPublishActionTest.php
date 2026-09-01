<?php

namespace Tests\Unit\Actions\Postsyncer;

use App\Actions\Postsyncer\EnqueuePostPublishAction;
use App\Jobs\PublishPostJob;
use App\Models\Post;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'default_language' => 'bangla',
            'languages' => [
                'bangla' => ['workspace_id' => '15211', 'platforms' => []],
            ],
        ]);
    }

    public function test_it_checkpoints_an_operation_and_dispatches_after_commit(): void
    {
        Queue::fake();

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $post = Post::factory()->for($workspace)->create([
            'status' => 'ready',
        ]);

        $queued = (new EnqueuePostPublishAction)->handle($post, $workspace, [
            'when' => '2026-09-02T09:20:00+06:00',
            'platforms' => ['facebook'],
            'confirm_ask' => false,
        ]);

        $progress = $queued->publish_progress;
        $this->assertIsArray($progress);
        $this->assertNotEmpty($progress['operation_id']);
        $this->assertNotEmpty($progress['run_token']);
        $this->assertSame('queued', $progress['state']);

        Queue::assertPushed(PublishPostJob::class, function (PublishPostJob $job) use ($queued, $progress): bool {
            return $job->post->is($queued)
                && $job->runToken === $progress['run_token']
                && $job->options['when'] === '2026-09-02T09:20:00+06:00';
        });
    }

    public function test_retry_preserves_original_options_and_operation_id(): void
    {
        Queue::fake();

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $post = Post::factory()->for($workspace)->create([
            'publish_state' => 'failed',
            'publish_progress' => [
                'version' => 1,
                'operation_id' => 'operation-1',
                'run_token' => 'old-run',
                'options' => [
                    'when' => '2026-09-02T09:20:00+06:00',
                    'confirm_ask' => false,
                ],
                'plan_hash' => null,
                'planned_groups' => [],
                'completed_groups' => [],
                'current' => null,
                'state' => 'failed',
            ],
        ]);

        (new EnqueuePostPublishAction)->handle($post, $workspace, []);

        $progress = $post->fresh()->publish_progress;
        $this->assertSame('operation-1', $progress['operation_id']);
        $this->assertNotSame('old-run', $progress['run_token']);
        $this->assertSame('queued', $progress['state']);
        Queue::assertPushed(PublishPostJob::class, function (PublishPostJob $job): bool {
            return $job->options['when'] === '2026-09-02T09:20:00+06:00';
        });
    }

    public function test_retry_rejects_an_uncertain_create(): void
    {
        Queue::fake();

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $post = Post::factory()->for($workspace)->create([
            'publish_state' => 'failed',
            'publish_progress' => [
                'version' => 1,
                'operation_id' => 'operation-1',
                'run_token' => 'run-1',
                'options' => [],
                'plan_hash' => 'plan-1',
                'planned_groups' => [],
                'completed_groups' => [],
                'current' => [
                    'index' => 0,
                    'group_key' => 'group-1',
                    'phase' => 'creating',
                    'idempotency_key' => 'request-1',
                    'media_ids' => [],
                ],
                'state' => 'uncertain',
            ],
        ]);

        $this->expectException(ValidationException::class);

        (new EnqueuePostPublishAction)->handle($post, $workspace, []);
    }
}
