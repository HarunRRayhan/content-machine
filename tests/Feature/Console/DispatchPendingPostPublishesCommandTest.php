<?php

namespace Tests\Feature\Console;

use App\Jobs\PublishPostJob;
use App\Models\Post;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DispatchPendingPostPublishesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requeues_a_queued_publish_with_its_checkpoint(): void
    {
        Queue::fake();
        $workspace = Workspace::factory()->create();
        $post = Post::factory()->for($workspace)->create([
            'publish_state' => 'queued',
            'publish_lease_id' => '72d9c4a1-58b0-4be7-95c0-a1d2227d2f22',
            'publish_progress' => [
                'version' => 1,
                'operation_id' => 'operation-123',
                'options' => ['when' => null, 'confirm_ask' => false],
                'plan_hash' => null,
                'planned_groups' => [],
                'completed_groups' => [],
                'current' => null,
                'state' => 'queued',
            ],
        ]);

        $this->artisan('postsyncer:dispatch-pending-publishes')
            ->assertSuccessful()
            ->expectsOutput('Dispatched 1 pending PostSyncer publish(es).');

        Queue::assertPushed(PublishPostJob::class, fn (PublishPostJob $job): bool => $job->post->is($post)
            && $job->operationId === 'operation-123'
            && $job->leaseId === '72d9c4a1-58b0-4be7-95c0-a1d2227d2f22');
    }

    public function test_it_requeues_a_legacy_queued_publish_without_progress(): void
    {
        Queue::fake();
        $post = Post::factory()->for(Workspace::factory())->create([
            'publish_state' => 'queued',
            'publish_progress' => null,
            'publish_lease_id' => null,
        ]);

        $this->artisan('postsyncer:dispatch-pending-publishes')
            ->assertSuccessful()
            ->expectsOutput('Dispatched 1 pending PostSyncer publish(es).');

        Queue::assertPushed(PublishPostJob::class, fn (PublishPostJob $job): bool => $job->post->is($post)
            && $job->options === []
            && $job->operationId === null
            && $job->leaseId === null);
    }

    public function test_it_requeues_an_expired_running_publish(): void
    {
        Queue::fake();
        $leaseId = '72d9c4a1-58b0-4be7-95c0-a1d2227d2f22';
        $post = Post::factory()->for(Workspace::factory())->create([
            'publish_state' => 'running',
            'publish_claimed_at' => now()->subSeconds(PublishPostJob::TIMEOUT_SECONDS + 1),
            'publish_lease_id' => $leaseId,
            'publish_progress' => [
                'version' => 1,
                'operation_id' => 'operation-123',
                'options' => ['when' => null, 'confirm_ask' => false],
                'plan_hash' => null,
                'planned_groups' => [],
                'completed_groups' => [],
                'current' => null,
                'state' => 'running',
            ],
        ]);

        $this->artisan('postsyncer:dispatch-pending-publishes')
            ->assertSuccessful()
            ->expectsOutput('Dispatched 1 pending PostSyncer publish(es).');

        Queue::assertPushed(PublishPostJob::class, fn (PublishPostJob $job): bool => $job->post->is($post)
            && $job->operationId === 'operation-123'
            && $job->leaseId === $leaseId);
    }
}
