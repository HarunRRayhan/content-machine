<?php

namespace Tests\Unit\Actions\Postsyncer;

use App\Actions\Postsyncer\EnqueueVideoPublishAction;
use App\Jobs\PublishVideoJob;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class EnqueueVideoPublishActionTest extends TestCase
{
    use RefreshDatabase;

    private function configureWorkspace(Workspace $workspace): void
    {
        PostsyncerConfig::write($workspace, [
            'publish_enabled' => true,
            'video_publish_enabled' => true,
            'api_key' => 'test-api-key',
            'default_language' => 'bangla',
            'languages' => [
                'bangla' => ['workspace_id' => '15211', 'platforms' => []],
            ],
        ]);
    }

    public function test_it_checkpoints_an_operation_and_dispatches_atomically(): void
    {
        Queue::fake();

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $video = Video::factory()->for($workspace)->create([
            'status' => 'recorded',
        ]);

        $queued = (new EnqueueVideoPublishAction)->handle($video, $workspace, [
            'when' => '2026-09-02T09:20:00+06:00',
            'platforms' => ['facebook'],
            'confirm_ask' => false,
        ]);

        $progress = $queued->publish_progress;
        $this->assertIsArray($progress);
        $this->assertNotEmpty($progress['operation_id']);
        $this->assertNotEmpty($progress['run_token']);
        $this->assertSame('queued', $progress['state']);

        Queue::assertPushed(PublishVideoJob::class, function (PublishVideoJob $job) use ($queued, $progress): bool {
            return $job->video->is($queued)
                && $job->runToken === $progress['run_token']
                && $job->options['when'] === '2026-09-02T09:20:00+06:00';
        });
    }

    public function test_queue_insert_failure_rolls_back_and_releases_the_unique_lock(): void
    {
        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $video = Video::factory()->for($workspace)->create([
            'status' => 'recorded',
        ]);
        $queuedJob = null;

        Event::listen(JobQueueing::class, function (JobQueueing $event) use (&$queuedJob): void {
            if ($event->job instanceof PublishVideoJob) {
                $queuedJob = $event->job;
                throw new RuntimeException('queue insert failed');
            }
        });

        $exception = null;
        try {
            (new EnqueueVideoPublishAction)->handle($video, $workspace, [
                'platforms' => ['facebook'],
                'confirm_ask' => false,
            ]);
        } catch (Throwable $thrown) {
            $exception = $thrown;
        }

        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertInstanceOf(PublishVideoJob::class, $queuedJob);
        $this->assertSame('recorded', $video->fresh()->status);
        $this->assertSame('idle', $video->fresh()->publish_state);
        $this->assertNull($video->fresh()->publish_progress);
        $this->assertDatabaseCount('jobs', 0);

        $lock = new UniqueLock(app(CacheRepository::class));
        $this->assertTrue($lock->acquire($queuedJob));
        $lock->release($queuedJob);
    }

    public function test_retry_preserves_original_options_and_operation_id(): void
    {
        Queue::fake();

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $video = Video::factory()->for($workspace)->create([
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

        (new EnqueueVideoPublishAction)->handle($video, $workspace, []);

        $progress = $video->fresh()->publish_progress;
        $this->assertSame('operation-1', $progress['operation_id']);
        $this->assertNotSame('old-run', $progress['run_token']);
        $this->assertSame('queued', $progress['state']);
        Queue::assertPushed(PublishVideoJob::class, function (PublishVideoJob $job): bool {
            return $job->options['when'] === '2026-09-02T09:20:00+06:00';
        });
    }

    public function test_retry_rejects_an_uncertain_create(): void
    {
        Queue::fake();

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $video = Video::factory()->for($workspace)->create([
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
                    'media_ids' => [915],
                ],
                'state' => 'uncertain',
            ],
        ]);

        $this->expectException(ValidationException::class);

        (new EnqueueVideoPublishAction)->handle($video, $workspace, []);

        Queue::assertNothingPushed();
    }

    public function test_retry_rejects_ask_confirmation_changes_with_retryable_media(): void
    {
        Queue::fake();

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $video = Video::factory()->for($workspace)->create([
            'publish_state' => 'failed',
            'publish_progress' => [
                'version' => 1,
                'operation_id' => 'operation-1',
                'run_token' => 'run-1',
                'options' => ['confirm_ask' => false],
                'plan_hash' => 'plan-1',
                'planned_groups' => [['index' => 0, 'group_key' => 'group-1']],
                'completed_groups' => [],
                'current' => [
                    'index' => 0,
                    'group_key' => 'group-1',
                    'phase' => 'retryable',
                    'idempotency_key' => 'request-1',
                    'media_ids' => [915],
                ],
                'state' => 'failed',
            ],
        ]);

        $this->expectException(ValidationException::class);

        (new EnqueueVideoPublishAction)->handle($video, $workspace, ['confirm_ask' => true]);

        Queue::assertNothingPushed();
    }
}
