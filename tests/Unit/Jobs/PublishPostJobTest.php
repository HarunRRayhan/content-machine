<?php

namespace Tests\Unit\Jobs;

use App\Actions\Postsyncer\PublishPostAction;
use App\Jobs\PublishPostJob;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PublishPostJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_delegates_to_the_action_with_options(): void
    {
        $post = Post::factory()->create();
        $options = ['when' => '2026-08-26T09:12:00+06:00', 'confirm_ask' => true];

        $action = Mockery::mock(PublishPostAction::class);
        $action->shouldReceive('handle')->once()->with(
            Mockery::on(fn (Post $p) => $p->is($post)),
            $options,
            Mockery::type('string'),
        );

        (new PublishPostJob($post, $options))->handle($action);
    }

    public function test_a_legacy_serialized_job_without_a_run_token_remains_safe_to_run(): void
    {
        $post = Post::factory()->create();
        $options = ['when' => '2026-08-26T09:12:00+06:00'];
        $job = unserialize(serialize(new PublishPostJob($post, $options)));

        $this->assertInstanceOf(PublishPostJob::class, $job);
        $this->assertSame('post:'.$post->getKey().':run:legacy', $job->uniqueId());

        $action = Mockery::mock(PublishPostAction::class);
        $action->shouldReceive('handle')->once()->with(
            Mockery::on(fn (Post $p) => $p->is($post)),
            $options,
            Mockery::type('string'),
        );

        $job->handle($action);
    }

    public function test_job_is_queued_on_the_isolated_postsyncer_worker(): void
    {
        $job = new PublishPostJob(Post::factory()->create(), []);

        $this->assertSame('postsyncer', $job->connection);
        $this->assertSame('postsyncer', $job->queue);
    }

    public function test_job_has_a_post_scoped_lock_and_long_external_api_budget(): void
    {
        $post = Post::factory()->create();
        $job = new PublishPostJob($post, [], 'run-1');
        $middleware = $job->middleware()[0];

        $this->assertSame(PublishPostJob::TIMEOUT_SECONDS, $job->timeout);
        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 60, 300], $job->backoff);
        $this->assertSame('post:'.$post->getKey().':run:run-1', $job->uniqueId());
        $this->assertSame(PublishPostJob::UNIQUE_FOR_SECONDS, $job->uniqueFor());
        $this->assertSame('postsyncer:post:'.$post->getKey(), $middleware->key);
        $this->assertSame(PublishPostJob::OVERLAP_EXPIRES_AFTER_SECONDS, $middleware->expiresAfter);
        $this->assertSame(60, $middleware->releaseAfter);
        $this->assertTrue($middleware->shareKey);
    }

    public function test_each_run_has_a_distinct_unique_queue_id(): void
    {
        $post = Post::factory()->create();
        $automaticRetry = new PublishPostJob($post, [], 'run-1');
        $manualRetry = new PublishPostJob($post, [], 'run-2');

        $this->assertNotSame($automaticRetry->uniqueId(), $manualRetry->uniqueId());
    }

    public function test_failed_job_marks_an_in_flight_create_as_uncertain_without_deleting_progress(): void
    {
        $post = Post::factory()->create([
            'publish_state' => 'running',
            'publish_progress' => [
                'version' => 1,
                'operation_id' => 'operation-1',
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

        $progressBeforeFailure = $post->publish_progress;
        (new PublishPostJob($post, []))->failed(new RuntimeException('job timed out'));

        $post->refresh();
        $this->assertSame('failed', $post->publish_state);
        $this->assertStringContainsString('outcome is uncertain', (string) $post->publish_error);
        $this->assertStringContainsString('job timed out', (string) $post->publish_error);
        $this->assertSame('uncertain', $post->publish_progress['state']);
        $this->assertSame(
            $progressBeforeFailure['current']['index'],
            $post->publish_progress['current']['index'],
        );
        $this->assertSame(
            $progressBeforeFailure['current']['group_key'],
            $post->publish_progress['current']['group_key'],
        );
    }

    public function test_stale_failed_job_does_not_overwrite_the_new_run(): void
    {
        $post = Post::factory()->create([
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
            'publish_error' => null,
        ]);

        (new PublishPostJob($post, [], 'old-run'))->failed(new RuntimeException('stale failure'));

        $post->refresh();
        $this->assertSame('queued', $post->publish_state);
        $this->assertNull($post->publish_error);
        $this->assertSame('new-run', $post->publish_progress['run_token']);
        $this->assertSame('queued', $post->publish_progress['state']);
    }

    public function test_a_legacy_failed_job_does_not_overwrite_a_run_with_a_durable_lease(): void
    {
        $post = Post::factory()->create([
            'publish_state' => 'running',
            'publish_lease_id' => '72d9c4a1-58b0-4be7-95c0-a1d2227d2f22',
            'publish_claimed_at' => now(),
            'publish_progress' => [
                'version' => 1,
                'operation_id' => 'operation-1',
                'run_token' => 'run-1',
                'options' => [],
                'plan_hash' => null,
                'planned_groups' => [],
                'completed_groups' => [],
                'current' => null,
                'state' => 'running',
            ],
        ]);

        (new PublishPostJob($post, [], 'run-1'))->failed(new RuntimeException('stale failure'));

        $post->refresh();
        $this->assertSame('running', $post->publish_state);
        $this->assertSame('72d9c4a1-58b0-4be7-95c0-a1d2227d2f22', $post->publish_lease_id);
        $this->assertNull($post->publish_error);
    }

    public function test_stale_explicit_run_does_not_invoke_the_publish_action(): void
    {
        $post = Post::factory()->create([
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

        $action = Mockery::mock(PublishPostAction::class);
        $action->shouldNotReceive('handle');

        (new PublishPostJob($post, [], 'old-run'))->handle($action);
    }
}
