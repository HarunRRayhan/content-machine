<?php

namespace Tests\Feature\Console;

use App\Actions\Postsyncer\PublishPostAction;
use App\Models\Post;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReconcilePostPublishCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_verifies_and_checkpoints_an_uncertain_post(): void
    {
        $workspace = Workspace::factory()->create();
        PostsyncerConfig::write($workspace, [
            'api_key' => 'test-api-key',
            'publish_enabled' => true,
            'languages' => [
                'bangla' => [
                    'workspace_id' => '15211',
                    'platforms' => [
                        'facebook' => ['account_id' => 100, 'handle' => '@harun'],
                    ],
                ],
            ],
            'post_types' => [
                'platforms' => [
                    'facebook' => ['text' => 'on'],
                ],
                'overrides' => [],
            ],
        ]);

        $post = Post::factory()->for($workspace)->create([
            'human_id' => 'P-RECONCILE',
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Scheduled caption'],
        ]);
        $options = [
            'when' => '2026-08-26T09:12:00+06:00',
            'confirm_ask' => false,
        ];

        Http::fake([
            'postsyncer.com/api/v1/posts' => Http::response([
                'message' => 'gateway timeout',
            ], 500),
        ]);
        app(PublishPostAction::class)->handle($post, $options);

        Http::fake([
            'postsyncer.com/api/v1/posts/99' => Http::response([
                'id' => 99,
                'workspace_id' => 15211,
                'content' => [[
                    'text' => 'Scheduled caption',
                    'media' => [],
                ]],
                'platforms' => [['platform' => 'facebook', 'account_id' => 100, 'settings' => [
                    'post_type' => 'POST', 'caption' => 'Scheduled caption',
                ]]],
                'status' => 'SCHEDULED',
                'scheduled_at' => '2026-08-26T09:12:00+06:00',
            ], 200),
        ]);

        $this->artisan('postsyncer:reconcile-post', [
            'workspace_id' => $workspace->id,
            'post' => $post->human_id,
            'postsyncer_id' => '99',
        ])
            ->expectsOutputToContain('was verified')
            ->assertExitCode(0);

        $post->refresh();
        $this->assertNull($post->publish_progress['current']);
        $this->assertSame('99', $post->publish_progress['completed_groups'][0]['post_id']);
    }

    public function test_it_checkpoints_reconciled_media_ids(): void
    {
        $workspace = Workspace::factory()->create();
        $post = Post::factory()->for($workspace)->create([
            'human_id' => 'P-MEDIA-RECONCILE',
            'status' => 'ready',
            'publish_state' => 'failed',
            'publish_progress' => [
                'version' => 1,
                'operation_id' => 'operation-1',
                'run_token' => 'run-1',
                'options' => ['when' => null, 'confirm_ask' => false],
                'plan_hash' => 'plan-1',
                'planned_groups' => [],
                'completed_groups' => [],
                'current' => [
                    'index' => 0,
                    'group_key' => 'group-1',
                    'phase' => 'uploading',
                    'idempotency_key' => 'request-1',
                    'media_ids' => [],
                    'media_urls' => ['https://example.com/image.png'],
                ],
                'state' => 'uncertain',
            ],
        ]);

        $this->artisan('postsyncer:reconcile-post-media', [
            'workspace_id' => $workspace->id,
            'post' => $post->human_id,
            'media_ids' => '915',
        ])
            ->expectsOutputToContain('checkpointed')
            ->assertExitCode(0);

        $post->refresh();
        $this->assertSame('failed', $post->publish_state);
        $this->assertSame('retryable', $post->publish_progress['current']['phase']);
        $this->assertSame(['915'], $post->publish_progress['current']['media_ids']);
    }

    public function test_it_recovers_a_drifted_post_from_its_stored_payload(): void
    {
        $workspace = Workspace::factory()->create();
        PostsyncerConfig::write($workspace, [
            'api_key' => 'test-api-key',
            'publish_enabled' => true,
            'languages' => [
                'bangla' => [
                    'workspace_id' => '15211',
                    'platforms' => [
                        'facebook' => ['account_id' => 100, 'handle' => '@harun'],
                    ],
                ],
            ],
            'post_types' => [
                'platforms' => ['facebook' => ['text' => 'on']],
                'overrides' => [],
            ],
        ]);
        $post = Post::factory()->for($workspace)->create([
            'human_id' => 'P-DRIFT-RECOVER',
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Original caption'],
        ]);

        Http::fake([
            'postsyncer.com/api/v1/posts' => Http::response([
                'id' => 99,
                'status' => 'published',
            ], 201),
            'postsyncer.com/api/v1/posts/99' => Http::response([
                'id' => 99,
                'workspace_id' => 15211,
                'content' => [['text' => 'Original caption', 'media' => []]],
                'platforms' => [['platform' => 'facebook', 'account_id' => 100, 'settings' => [
                    'post_type' => 'POST', 'caption' => 'Original caption',
                ]]],
                'status' => 'PUBLISHED',
            ], 200),
        ]);

        app(PublishPostAction::class)->handle($post, ['confirm_ask' => false]);
        $progress = $post->fresh()->publish_progress;
        $post->forceFill([
            'captions' => ['facebook' => 'Changed caption'],
            'postsyncer' => null,
            'publish_state' => 'failed',
            'publish_error' => 'plan drift',
            'publish_progress' => array_merge($progress, ['state' => 'failed']),
        ])->save();

        $this->artisan('postsyncer:recover-post-plan-drift', [
            'workspace_id' => $workspace->id,
            'post' => $post->human_id,
        ])
            ->expectsOutputToContain('recovered')
            ->assertExitCode(0);

        $post->refresh();
        $this->assertSame('succeeded', $post->publish_state);
        $this->assertSame('99', $post->postsyncer['groups'][0]['post_id']);
    }
}
