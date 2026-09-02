<?php

namespace Tests\Feature\Console;

use App\Actions\Postsyncer\PublishVideoAction;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReconcileVideoPublishCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_verifies_and_checkpoints_an_uncertain_video(): void
    {
        $workspace = Workspace::factory()->create();
        PostsyncerConfig::write($workspace, [
            'api_key' => 'test-api-key',
            'publish_enabled' => true,
            'video_publish_enabled' => true,
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
                    'facebook' => ['reel' => 'on'],
                ],
                'overrides' => [],
            ],
        ]);

        $video = Video::factory()->for($workspace)->create([
            'human_id' => 'BV-RECONCILE',
            'status' => 'recorded',
            'language' => 'bn',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'captions' => ['facebook' => 'Scheduled caption'],
        ]);
        $options = [
            'when' => '2026-08-26T09:12:00+06:00',
            'confirm_ask' => false,
        ];

        Http::fake([
            'postsyncer.com/api/v1/media/upload/url' => Http::response([
                'media' => [['id' => 915]],
                'count_stored' => 1,
            ], 200),
            'postsyncer.com/api/v1/posts' => Http::response([
                'message' => 'gateway timeout',
            ], 500),
        ]);
        app(PublishVideoAction::class)->handle($video, $options);

        Http::fake([
            'postsyncer.com/api/v1/posts/99' => Http::response([
                'id' => 99,
                'workspace_id' => 15211,
                'content' => [[
                    'text' => 'Scheduled caption',
                    'media' => [['id' => 915]],
                ]],
                'platforms' => [[
                    'platform' => 'facebook',
                    'account_id' => 100,
                    'settings' => [
                        'post_type' => 'REELS',
                        'caption' => 'Scheduled caption',
                    ],
                ]],
                'status' => 'SCHEDULED',
                'scheduled_at' => '2026-08-26T09:12:00+06:00',
            ], 200),
        ]);

        $this->artisan('postsyncer:reconcile-video', [
            'workspace_id' => $workspace->id,
            'video' => $video->human_id,
            'postsyncer_id' => '99',
        ])
            ->expectsOutputToContain('was verified')
            ->assertExitCode(0);

        $video->refresh();
        $this->assertNull($video->publish_progress['current']);
        $this->assertSame('99', $video->publish_progress['completed_groups'][0]['post_id']);
        $this->assertIsArray($video->publish_progress['completed_groups'][0]['expected_payload']);
    }

    public function test_it_checkpoints_reconciled_media_ids(): void
    {
        $workspace = Workspace::factory()->create();
        $video = Video::factory()->for($workspace)->create([
            'human_id' => 'BV-MEDIA-RECONCILE',
            'status' => 'recorded',
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
                    'media_urls' => ['https://example.com/video.mp4'],
                ],
                'state' => 'uncertain',
            ],
        ]);

        $this->artisan('postsyncer:reconcile-video-media', [
            'workspace_id' => $workspace->id,
            'video' => $video->human_id,
            'media_ids' => '915',
        ])
            ->expectsOutputToContain('checkpointed')
            ->assertExitCode(0);

        $video->refresh();
        $this->assertSame('failed', $video->publish_state);
        $this->assertSame('retryable', $video->publish_progress['current']['phase']);
        $this->assertSame(['915'], $video->publish_progress['current']['media_ids']);
    }

    public function test_it_recovers_a_drifted_video_from_its_stored_payload(): void
    {
        $workspace = Workspace::factory()->create();
        PostsyncerConfig::write($workspace, [
            'api_key' => 'test-api-key',
            'publish_enabled' => true,
            'video_publish_enabled' => true,
            'languages' => [
                'bangla' => [
                    'workspace_id' => '15211',
                    'platforms' => [
                        'facebook' => ['account_id' => 100, 'handle' => '@harun'],
                    ],
                ],
            ],
            'post_types' => [
                'platforms' => ['facebook' => ['reel' => 'on']],
                'overrides' => [],
            ],
        ]);
        $video = Video::factory()->for($workspace)->create([
            'human_id' => 'BV-DRIFT-RECOVER',
            'status' => 'recorded',
            'language' => 'bn',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'captions' => ['facebook' => 'Original caption'],
        ]);

        Http::fake([
            'postsyncer.com/api/v1/media/upload/url' => Http::response([
                'media' => [['id' => 915]],
                'count_stored' => 1,
            ], 200),
            'postsyncer.com/api/v1/posts' => Http::response([
                'id' => 99,
                'status' => 'published',
            ], 201),
            'postsyncer.com/api/v1/posts/99' => Http::response([
                'id' => 99,
                'workspace_id' => 15211,
                'content' => [['text' => 'Original caption', 'media' => [['id' => 915]]]],
                'platforms' => [['platform' => 'facebook', 'account_id' => 100, 'settings' => [
                    'post_type' => 'REELS', 'caption' => 'Original caption',
                ]]],
                'status' => 'PUBLISHED',
            ], 200),
        ]);

        app(PublishVideoAction::class)->handle($video, ['confirm_ask' => false]);
        $progress = $video->fresh()->publish_progress;
        $video->forceFill([
            'captions' => ['facebook' => 'Changed caption'],
            'postsyncer' => null,
            'publish_state' => 'failed',
            'publish_error' => 'plan drift',
            'publish_progress' => array_merge($progress, ['state' => 'failed']),
        ])->save();

        $this->artisan('postsyncer:recover-video-plan-drift', [
            'workspace_id' => $workspace->id,
            'video' => $video->human_id,
        ])
            ->expectsOutputToContain('recovered')
            ->assertExitCode(0);

        $video->refresh();
        $this->assertSame('succeeded', $video->publish_state);
        $this->assertSame('99', $video->postsyncer['groups'][0]['post_id']);
    }
}
