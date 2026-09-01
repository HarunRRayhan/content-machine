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
    }
}
