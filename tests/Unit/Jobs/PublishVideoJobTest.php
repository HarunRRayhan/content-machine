<?php

namespace Tests\Unit\Jobs;

use App\Actions\Postsyncer\PublishVideoAction;
use App\Jobs\PublishVideoJob;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PublishVideoJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_delegates_to_the_action_with_options(): void
    {
        $video = Video::factory()->create();
        $options = ['when' => '2026-08-26T09:12:00+06:00', 'confirm_ask' => true];

        $action = Mockery::mock(PublishVideoAction::class);
        $action->shouldReceive('handle')->once()->with(
            Mockery::on(fn (Video $v) => $v->is($video)),
            $options,
        );

        (new PublishVideoJob($video, $options))->handle($action);
    }

    public function test_job_has_a_video_scoped_lock_and_long_external_api_budget(): void
    {
        $video = Video::factory()->create();
        $job = new PublishVideoJob($video, [], 'run-1');
        $middleware = $job->middleware()[0];

        $this->assertSame(PublishVideoJob::TIMEOUT_SECONDS, $job->timeout);
        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 60, 300], $job->backoff);
        $this->assertSame('video:'.$video->getKey().':run:run-1', $job->uniqueId());
        $this->assertSame(PublishVideoJob::UNIQUE_FOR_SECONDS, $job->uniqueFor());
        $this->assertSame('postsyncer:video:'.$video->getKey(), $middleware->key);
        $this->assertSame(PublishVideoJob::OVERLAP_EXPIRES_AFTER_SECONDS, $middleware->expiresAfter);
        $this->assertSame(60, $middleware->releaseAfter);
        $this->assertTrue($middleware->shareKey);
    }

    public function test_failed_job_marks_an_in_flight_create_as_uncertain_without_deleting_progress(): void
    {
        $video = Video::factory()->create([
            'publish_state' => 'running',
            'publish_progress' => [
                'version' => 1,
                'operation_id' => 'operation-1',
                'run_token' => 'run-1',
                'options' => ['when' => '2026-09-02T09:20:00+06:00'],
                'plan_hash' => 'plan-1',
                'planned_groups' => [],
                'completed_groups' => [],
                'current' => [
                    'index' => 0,
                    'group_key' => 'group-1',
                    'phase' => 'creating',
                ],
                'state' => 'running',
            ],
        ]);

        $progressBeforeFailure = $video->publish_progress;
        (new PublishVideoJob($video, [], 'run-1'))->failed(new RuntimeException('job timed out'));

        $video->refresh();
        $this->assertSame('failed', $video->publish_state);
        $this->assertStringContainsString('outcome is uncertain', (string) $video->publish_error);
        $this->assertStringContainsString('job timed out', (string) $video->publish_error);
        $this->assertSame('uncertain', $video->publish_progress['state']);
        $this->assertSame(
            $progressBeforeFailure['current']['index'],
            $video->publish_progress['current']['index'],
        );
        $this->assertSame(
            $progressBeforeFailure['current']['group_key'],
            $video->publish_progress['current']['group_key'],
        );
    }

    public function test_stale_failure_callback_does_not_overwrite_a_newer_run(): void
    {
        $video = Video::factory()->create([
            'publish_state' => 'queued',
            'publish_progress' => [
                'version' => 1,
                'operation_id' => 'operation-1',
                'run_token' => 'new-run',
                'options' => [],
                'plan_hash' => null,
                'planned_groups' => [],
                'completed_groups' => [],
                'current' => null,
                'state' => 'queued',
            ],
        ]);

        (new PublishVideoJob($video, [], 'old-run'))->failed(new RuntimeException('late failure'));

        $video->refresh();
        $this->assertSame('queued', $video->publish_state);
        $this->assertNull($video->publish_error);
        $this->assertSame('new-run', $video->publish_progress['run_token']);
    }

    public function test_stale_explicit_run_does_not_invoke_the_publish_action(): void
    {
        $video = Video::factory()->create([
            'publish_state' => 'queued',
            'publish_progress' => [
                'version' => 1,
                'operation_id' => 'operation-1',
                'run_token' => 'new-run',
                'options' => [],
                'plan_hash' => null,
                'planned_groups' => [],
                'current' => null,
                'state' => 'queued',
            ],
        ]);

        $action = Mockery::mock(PublishVideoAction::class);
        $action->shouldNotReceive('handle');

        (new PublishVideoJob($video, [], 'old-run'))->handle($action);
    }
}
