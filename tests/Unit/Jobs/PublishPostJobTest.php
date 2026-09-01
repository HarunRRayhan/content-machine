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
        );

        (new PublishPostJob($post, $options))->handle($action);
    }

    public function test_job_is_queued_on_the_dedicated_postsyncer_worker(): void
    {
        $job = new PublishPostJob(Post::factory()->create(), []);

        $this->assertSame('postsyncer', $job->connection);
        $this->assertSame('postsyncer', $job->queue);
    }

    public function test_a_legacy_serialized_job_defaults_operation_and_lease_ids(): void
    {
        $job = new PublishPostJob(Post::factory()->create(), []);
        $legacyJob = unserialize(serialize($job));

        $this->assertInstanceOf(PublishPostJob::class, $legacyJob);
        $this->assertNull($legacyJob->operationId);
        $this->assertNull($legacyJob->leaseId);
    }

    public function test_a_stale_failure_hook_cannot_overwrite_a_newer_publish_lease(): void
    {
        $post = Post::factory()->create([
            'publish_state' => 'queued',
            'publish_lease_id' => '72d9c4a1-58b0-4be7-95c0-a1d2227d2f22',
            'publish_progress' => [
                'version' => 1,
                'operation_id' => 'operation-123',
                'options' => [],
                'plan_hash' => null,
                'planned_groups' => [],
                'completed_groups' => [],
                'current' => null,
                'state' => 'queued',
            ],
        ]);

        (new PublishPostJob(
            $post,
            [],
            'operation-123',
            'different-lease',
        ))->failed(new RuntimeException('old worker failed'));

        $this->assertSame('queued', $post->refresh()->publish_state);
        $this->assertSame('72d9c4a1-58b0-4be7-95c0-a1d2227d2f22', $post->publish_lease_id);
    }

    public function test_failure_hook_clears_the_expired_publish_lease(): void
    {
        $leaseId = '72d9c4a1-58b0-4be7-95c0-a1d2227d2f22';
        $post = Post::factory()->create([
            'publish_state' => 'running',
            'publish_claimed_at' => now()->subMinutes(20),
            'publish_lease_id' => $leaseId,
            'publish_progress' => [
                'version' => 1,
                'operation_id' => 'operation-123',
                'options' => [],
                'plan_hash' => null,
                'planned_groups' => [],
                'completed_groups' => [],
                'current' => [
                    'index' => 0,
                    'group_key' => 'group-1',
                    'phase' => 'creating',
                    'idempotency_key' => 'idempotency-1',
                    'media_ids' => [],
                ],
                'state' => 'running',
            ],
        ]);

        (new PublishPostJob($post, [], 'operation-123', $leaseId))
            ->failed(new RuntimeException('worker timed out'));

        $post->refresh();
        $this->assertSame('failed', $post->publish_state);
        $this->assertNull($post->publish_claimed_at);
        $this->assertNull($post->publish_lease_id);
        $this->assertSame('uncertain', $post->publish_progress['state']);
    }
}
