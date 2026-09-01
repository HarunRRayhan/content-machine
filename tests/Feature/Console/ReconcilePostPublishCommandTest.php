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
                'platforms' => [['platform' => 'facebook']],
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
}
