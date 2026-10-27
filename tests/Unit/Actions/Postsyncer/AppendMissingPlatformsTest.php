<?php

namespace Tests\Unit\Actions\Postsyncer;

use App\Actions\Postsyncer\EnqueuePostPublishAction;
use App\Actions\Postsyncer\PublishPostAction;
use App\Jobs\PublishPostJob;
use App\Models\Post;
use App\Models\Workspace;
use App\Support\Postsyncer\MediaUrlResolver;
use App\Support\Postsyncer\PostPublishPlanner;
use App\Support\Postsyncer\PostsyncerConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AppendMissingPlatformsTest extends TestCase
{
    use RefreshDatabase;

    private const WHEN = '2026-10-10T09:00:00+06:00';

    /** @var array<string, mixed> */
    private array $existingGroup = [
        'post_id' => '500',
        'status' => 'SCHEDULED',
        'scheduled_at' => '2026-10-10T08:00:00+06:00',
        'platforms' => ['facebook'],
        'language' => 'bangla',
    ];

    private function workspace(): Workspace
    {
        $workspace = Workspace::factory()->create();
        PostsyncerConfig::write($workspace, [
            'api_key' => 'test-api-key',
            'publish_enabled' => true,
            'default_language' => 'bangla',
            'languages' => [
                'bangla' => [
                    'workspace_id' => '15211',
                    'platforms' => [
                        'facebook' => ['account_id' => 100, 'handle' => '@harun'],
                        'linkedin' => ['account_id' => 102, 'handle' => '@harun.li'],
                        'twitter' => ['account_id' => 103, 'handle' => '@harun.x'],
                    ],
                ],
            ],
            'post_types' => [
                'platforms' => [
                    'facebook' => ['text' => 'on'],
                    'linkedin' => ['text' => 'on'],
                    'twitter' => ['text' => 'on'],
                ],
                'overrides' => [],
            ],
        ]);

        return $workspace;
    }

    private function scheduledPost(Workspace $workspace, array $overrides = []): Post
    {
        return Post::factory()->for($workspace)->create(array_merge([
            'status' => 'scheduled',
            'publish_state' => 'succeeded',
            'language' => 'bn',
            'platforms' => ['facebook', 'linkedin', 'twitter'],
            'captions' => [
                'facebook' => 'FB caption',
                'linkedin' => 'LI caption',
                'twitter' => 'X caption',
            ],
            'postsyncer' => ['groups' => [$this->existingGroup]],
        ], $overrides));
    }

    private function enqueue(Post $post, Workspace $workspace, array $options): Post
    {
        return (new EnqueuePostPublishAction)->handle($post, $workspace, $options + ['append_missing' => true]);
    }

    private function assertRejected(Post $post, Workspace $workspace, array $options, string $key, string $contains): void
    {
        try {
            $this->enqueue($post, $workspace, $options);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors());
            $this->assertStringContainsString($contains, $e->errors()[$key][0]);
        }

        Queue::assertNothingPushed();
        $fresh = $post->fresh();
        $this->assertSame('succeeded', $fresh->publish_state);
        $this->assertEquals([$this->existingGroup], $fresh->postsyncer['groups']);
    }

    public function test_without_the_flag_an_already_scheduled_post_is_still_refused(): void
    {
        Queue::fake();
        $workspace = $this->workspace();
        $post = $this->scheduledPost($workspace);

        try {
            (new EnqueuePostPublishAction)->handle($post, $workspace, [
                'when' => self::WHEN, 'platforms' => ['linkedin'],
            ]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Republish is not supported', $e->errors()['publish'][0]);
        }
        Queue::assertNothingPushed();
    }

    public function test_append_requires_when_and_platforms(): void
    {
        Queue::fake();
        $workspace = $this->workspace();
        $post = $this->scheduledPost($workspace);

        $this->assertRejected($post, $workspace, ['platforms' => ['linkedin']], 'when', 'requires an explicit schedule');
        $this->assertRejected($post, $workspace, ['when' => self::WHEN], 'platforms', 'Specify the platforms');
        $this->assertRejected($post, $workspace, ['when' => self::WHEN, 'platforms' => []], 'platforms', 'Specify the platforms');
    }

    public function test_already_scheduled_platform_is_rejected_by_name(): void
    {
        Queue::fake();
        $workspace = $this->workspace();
        $post = $this->scheduledPost($workspace);

        $this->assertRejected(
            $post,
            $workspace,
            ['when' => self::WHEN, 'platforms' => ['facebook', 'linkedin']],
            'platforms',
            'already scheduled on PostSyncer: facebook',
        );
    }

    public function test_platform_without_a_caption_is_rejected(): void
    {
        Queue::fake();
        $workspace = $this->workspace();
        $post = $this->scheduledPost($workspace, ['captions' => ['facebook' => 'FB', 'twitter' => 'X']]);

        $this->assertRejected(
            $post,
            $workspace,
            ['when' => self::WHEN, 'platforms' => ['linkedin']],
            'platforms',
            'not publishable',
        );
    }

    public function test_failed_or_running_state_is_not_overwritten(): void
    {
        Queue::fake();
        $workspace = $this->workspace();
        $post = $this->scheduledPost($workspace, [
            'publish_state' => 'failed',
            'publish_progress' => ['version' => 1, 'operation_id' => 'old', 'state' => 'failed'],
        ]);

        try {
            $this->enqueue($post, $workspace, ['when' => self::WHEN, 'platforms' => ['linkedin']]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('publish', $e->errors());
        }

        $this->assertSame('old', $post->fresh()->publish_progress['operation_id']);
        Queue::assertNothingPushed();
    }

    public function test_enqueue_queues_only_missing_platforms_and_snapshots_base_groups(): void
    {
        Queue::fake();
        $workspace = $this->workspace();
        $post = $this->scheduledPost($workspace);

        $queued = $this->enqueue($post, $workspace, [
            'when' => self::WHEN, 'platforms' => ['linkedin', 'twitter'],
        ]);

        $this->assertSame('queued', $queued->publish_state);
        $this->assertEquals([$this->existingGroup], $queued->postsyncer['groups']);
        $this->assertEquals([$this->existingGroup], $queued->publish_progress['base_groups']);
        $this->assertTrue($queued->publish_progress['options']['append_missing']);
        Queue::assertPushed(PublishPostJob::class, 1);

        // A second request while queued cannot double-enqueue.
        try {
            $this->enqueue($queued, $workspace, ['when' => self::WHEN, 'platforms' => ['linkedin']]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already in progress', $e->errors()['publish'][0]);
        }
        Queue::assertPushed(PublishPostJob::class, 1);
    }

    public function test_flag_is_ignored_for_a_post_with_no_groups(): void
    {
        Queue::fake();
        $workspace = $this->workspace();
        $post = $this->scheduledPost($workspace, ['postsyncer' => null, 'publish_state' => 'idle', 'status' => 'ready']);

        $queued = $this->enqueue($post, $workspace, ['when' => self::WHEN, 'platforms' => ['facebook']]);

        $this->assertArrayNotHasKey('append_missing', $queued->publish_progress['options']);
        $this->assertArrayNotHasKey('base_groups', $queued->publish_progress);
    }

    public function test_publish_creates_only_missing_groups_and_preserves_existing_ones(): void
    {
        Queue::fake();
        Http::fake([
            'postsyncer.com/api/v1/posts' => Http::response([
                'id' => 777,
                'status' => 'scheduled',
                'scheduled_at' => self::WHEN,
            ], 201),
            'postsyncer.com/api/v1/posts/777' => Http::response([
                'id' => 777,
                'workspace_id' => 15211,
                'content' => [['text' => 'LI caption', 'media' => []]],
                'platforms' => [['platform' => 'linkedin', 'account_id' => 102, 'settings' => [
                    'caption' => 'LI caption',
                ]]],
                'status' => 'SCHEDULED',
                'scheduled_at' => self::WHEN,
            ], 200),
        ]);

        $workspace = $this->workspace();
        $post = $this->scheduledPost($workspace);
        $queued = $this->enqueue($post, $workspace, ['when' => self::WHEN, 'platforms' => ['linkedin']]);

        $job = null;
        Queue::assertPushed(PublishPostJob::class, function (PublishPostJob $pushed) use (&$job): bool {
            $job = $pushed;

            return true;
        });

        $action = new PublishPostAction(new PostPublishPlanner(new MediaUrlResolver));
        $run = fn () => $action->handle(
            $queued,
            $job->options,
            $job->runToken,
            $job->operationId,
            $job->leaseId,
        );
        $run();

        $fresh = $post->fresh();
        $this->assertSame('succeeded', $fresh->publish_state);
        $this->assertSame('scheduled', $fresh->status);
        $groups = $fresh->postsyncer['groups'];
        $this->assertCount(2, $groups);
        $this->assertEquals($this->existingGroup, $groups[0]);
        $this->assertSame('777', $groups[1]['post_id']);
        $this->assertSame(['linkedin'], $groups[1]['platforms']);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->url() !== 'https://postsyncer.com/api/v1/posts'
            || count($request['accounts'] ?? [1]) === 1);

        // Duplicate delivery after success is a no-op: no further PostSyncer calls.
        $run();
        Http::assertSentCount(2);
        $this->assertCount(2, $post->fresh()->postsyncer['groups']);

        // A fresh request for the same platform is now refused as already scheduled.
        $this->assertRejectedAfterSuccess($post->fresh(), $workspace);
    }

    private function assertRejectedAfterSuccess(Post $post, Workspace $workspace): void
    {
        try {
            $this->enqueue($post, $workspace, ['when' => self::WHEN, 'platforms' => ['linkedin']]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already scheduled on PostSyncer: linkedin', $e->errors()['platforms'][0]);
        }
    }

    public function test_finalize_fails_closed_if_existing_groups_changed_underneath(): void
    {
        Queue::fake();
        Http::fake([
            'postsyncer.com/api/v1/posts' => Http::response([
                'id' => 778, 'status' => 'scheduled', 'scheduled_at' => self::WHEN,
            ], 201),
            'postsyncer.com/api/v1/posts/778' => Http::response([
                'id' => 778,
                'workspace_id' => 15211,
                'content' => [['text' => 'LI caption', 'media' => []]],
                'platforms' => [['platform' => 'linkedin', 'account_id' => 102, 'settings' => ['caption' => 'LI caption']]],
                'status' => 'SCHEDULED',
                'scheduled_at' => self::WHEN,
            ], 200),
        ]);

        $workspace = $this->workspace();
        $post = $this->scheduledPost($workspace);
        $queued = $this->enqueue($post, $workspace, ['when' => self::WHEN, 'platforms' => ['linkedin']]);
        $job = null;
        Queue::assertPushed(PublishPostJob::class, function (PublishPostJob $pushed) use (&$job): bool {
            $job = $pushed;

            return true;
        });

        $tampered = $this->existingGroup;
        $tampered['status'] = 'PUBLISHED';
        Post::query()->whereKey($post->id)->update(['postsyncer' => json_encode(['groups' => [$tampered]])]);

        (new PublishPostAction(new PostPublishPlanner(new MediaUrlResolver)))->handle(
            $queued, $job->options, $job->runToken, $job->operationId, $job->leaseId,
        );

        $fresh = $post->fresh();
        $this->assertSame('failed', $fresh->publish_state);
        $this->assertEquals([$tampered], $fresh->postsyncer['groups']);
        $this->assertStringContainsString('changed while the append', $fresh->publish_error);
    }
}
