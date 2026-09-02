<?php

namespace Tests\Unit\Actions\Postsyncer;

use App\Actions\Postsyncer\PublishVideoAction;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Postsyncer\MediaUrlResolver;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Postsyncer\VideoPublishPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublishVideoActionTest extends TestCase
{
    use RefreshDatabase;

    private PublishVideoAction $action;

    /**
     * @return array<string, mixed>
     */
    private function samplePostTypes(): array
    {
        return [
            'platforms' => [
                'facebook' => ['reel' => 'on'],
                'instagram' => ['reel' => 'on'],
            ],
            'overrides' => [],
        ];
    }

    private function configureWorkspace(Workspace $workspace): void
    {
        PostsyncerConfig::write($workspace, [
            'api_key' => 'test-api-key',
            'video_publish_enabled' => true,
            'languages' => [
                'bangla' => [
                    'workspace_id' => '15211',
                    'platforms' => [
                        'facebook' => ['account_id' => 100, 'handle' => '@harun'],
                        'instagram' => ['account_id' => 101, 'handle' => '@harun.ig'],
                    ],
                ],
            ],
            'post_types' => $this->samplePostTypes(),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = new PublishVideoAction(new VideoPublishPlanner(new MediaUrlResolver));
    }

    public function test_publish_now_sets_succeeded_status_posted_and_groups(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/media/upload/url' => Http::response([
                'media' => [['id' => 915], ['id' => 916]],
            ], 200),
            'postsyncer.com/api/v1/posts' => Http::response([
                'id' => 42,
                'status' => 'published',
            ], 201),
        ]);

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);

        $video = Video::factory()->for($workspace)->create([
            'status' => 'recorded',
            'publish_state' => 'queued',
            'language' => 'bn',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'cover_drive_url' => 'https://drive.google.com/file/d/cover/view',
            'captions' => [
                'main' => [
                    'facebook' => ['caption' => 'FB reel caption'],
                    'instagram' => ['caption' => 'IG reel caption'],
                ],
            ],
        ]);

        $this->action->handle($video, [
            'platforms' => ['facebook', 'instagram'],
            'confirm_ask' => false,
        ]);

        $video->refresh();
        $this->assertSame('succeeded', $video->publish_state);
        $this->assertNull($video->publish_error);
        $this->assertSame('posted', $video->status);
        $this->assertEquals([
            'groups' => [[
                'post_id' => '42',
                'status' => 'PUBLISHED',
                'scheduled_at' => null,
                'platforms' => ['facebook', 'instagram'],
                'language' => 'bangla',
            ]],
        ], $video->postsyncer);

        Http::assertSentCount(2);
    }

    public function test_schedule_sets_status_scheduled(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/media/upload/url' => Http::response([
                'media' => [['id' => 915]],
            ], 200),
            'postsyncer.com/api/v1/posts' => Http::response([
                'id' => 99,
                'status' => 'scheduled',
                'scheduled_at' => '2026-08-26T09:12:00+06:00',
            ], 201),
        ]);

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);

        $video = Video::factory()->for($workspace)->create([
            'status' => 'recorded',
            'language' => 'bn',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'captions' => ['facebook' => 'Scheduled reel caption'],
        ]);

        $this->action->handle($video, [
            'platforms' => ['facebook'],
            'confirm_ask' => false,
            'when' => '2026-08-26T09:12:00+06:00',
        ]);

        $video->refresh();
        $this->assertSame('succeeded', $video->publish_state);
        $this->assertSame('scheduled', $video->status);
        $this->assertSame('SCHEDULED', $video->postsyncer['groups'][0]['status']);
        $this->assertSame('2026-08-26T09:12:00+06:00', $video->postsyncer['groups'][0]['scheduled_at']);

        Http::assertSent(fn ($request) => $request->url() === 'https://postsyncer.com/api/v1/posts'
            && $request['schedule_type'] === 'schedule'
            && $request['schedule_for']['date'] === '2026-08-26'
            && $request['schedule_for']['timezone'] === 'Asia/Dhaka');
    }

    public function test_naive_when_sends_workspace_timezone_on_schedule_for(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/media/upload/url' => Http::response([
                'media' => [['id' => 915]],
            ], 200),
            'postsyncer.com/api/v1/posts' => Http::response([
                'id' => 99,
                'status' => 'scheduled',
                'scheduled_at' => '2026-08-26T09:12:00+06:00',
            ], 201),
        ]);

        $workspace = Workspace::factory()->create(['timezone' => 'Asia/Dhaka']);
        $this->configureWorkspace($workspace);

        $video = Video::factory()->for($workspace)->create([
            'status' => 'recorded',
            'language' => 'bn',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'captions' => ['facebook' => 'Scheduled reel caption'],
        ]);

        $this->action->handle($video, [
            'when' => '2026-08-26T09:12',
            'confirm_ask' => false,
        ]);

        $video->refresh();
        $this->assertSame('succeeded', $video->publish_state);
        $this->assertSame('scheduled', $video->status);

        Http::assertSent(fn ($request) => $request->url() === 'https://postsyncer.com/api/v1/posts'
            && $request['schedule_type'] === 'schedule'
            && $request['schedule_for']['date'] === '2026-08-26'
            && $request['schedule_for']['time'] === '09:12'
            && $request['schedule_for']['timezone'] === 'Asia/Dhaka');
    }

    public function test_empty_plan_fails_and_leaves_status_unchanged(): void
    {
        Http::fake();

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);

        $video = Video::factory()->for($workspace)->create([
            'status' => 'recorded',
            'language' => 'bn',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'captions' => [],
        ]);

        $this->action->handle($video, [
            'platforms' => ['facebook'],
            'confirm_ask' => false,
        ]);

        $video->refresh();
        $this->assertSame('failed', $video->publish_state);
        $this->assertStringContainsString('No PostSyncer publish groups', $video->publish_error);
        $this->assertSame('recorded', $video->status);
        $this->assertNull($video->postsyncer);

        Http::assertNothingSent();
    }

    public function test_second_publish_is_refused_when_groups_have_post_ids(): void
    {
        Http::fake();

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);

        $video = Video::factory()->for($workspace)->create([
            'status' => 'scheduled',
            'language' => 'bn',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'captions' => ['facebook' => 'Already scheduled'],
            'postsyncer' => [
                'groups' => [[
                    'post_id' => '42',
                    'status' => 'SCHEDULED',
                    'scheduled_at' => '2026-08-26T09:12:00+06:00',
                    'platforms' => ['facebook'],
                    'language' => 'bangla',
                ]],
            ],
        ]);

        $this->action->handle($video, [
            'platforms' => ['facebook'],
            'confirm_ask' => false,
        ]);

        $video->refresh();
        $this->assertSame('failed', $video->publish_state);
        $this->assertStringContainsString('already has PostSyncer posts', $video->publish_error);
        $this->assertSame('scheduled', $video->status);
        $this->assertSame('42', $video->postsyncer['groups'][0]['post_id']);

        Http::assertNothingSent();
    }

    public function test_empty_groups_without_post_ids_is_treated_as_first_publish(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/media/upload/url' => Http::response([
                'media' => [['id' => 915]],
            ], 200),
            'postsyncer.com/api/v1/posts' => Http::response([
                'id' => 77,
                'status' => 'published',
            ], 201),
        ]);

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);

        $video = Video::factory()->for($workspace)->create([
            'status' => 'recorded',
            'language' => 'bn',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'captions' => ['facebook' => 'Retry after empty plan'],
            'postsyncer' => ['groups' => []],
        ]);

        $this->action->handle($video, [
            'platforms' => ['facebook'],
            'confirm_ask' => false,
        ]);

        $video->refresh();
        $this->assertSame('succeeded', $video->publish_state);
        $this->assertSame('posted', $video->status);
        $this->assertSame('77', $video->postsyncer['groups'][0]['post_id']);
    }

    public function test_failure_leaves_pipeline_status_and_sets_publish_error(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/media/upload/url' => Http::response([
                'message' => 'Invalid workspace',
            ], 422),
        ]);

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);

        $video = Video::factory()->for($workspace)->create([
            'status' => 'recorded',
            'language' => 'bn',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'cover_drive_url' => 'https://drive.google.com/file/d/cover/view',
            'captions' => [
                'main' => [
                    'facebook' => ['caption' => 'FB reel caption'],
                    'instagram' => ['caption' => 'IG reel caption'],
                ],
            ],
        ]);

        $this->action->handle($video, [
            'platforms' => ['facebook', 'instagram'],
            'confirm_ask' => false,
        ]);

        $video->refresh();
        $this->assertSame('failed', $video->publish_state);
        $this->assertStringContainsString('PostSyncer API error 422', $video->publish_error);
        $this->assertSame('recorded', $video->status);
        $this->assertNull($video->postsyncer);
    }

    public function test_missing_video_drive_url_fails_without_changing_status(): void
    {
        Http::fake();

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);

        $video = Video::factory()->for($workspace)->create([
            'status' => 'recorded',
            'language' => 'bn',
            'video_drive_url' => null,
            'captions' => ['facebook' => 'FB caption'],
        ]);

        $this->action->handle($video, [
            'platforms' => ['facebook'],
            'confirm_ask' => false,
        ]);

        $video->refresh();
        $this->assertSame('failed', $video->publish_state);
        $this->assertStringContainsString('video_drive_url', $video->publish_error);
        $this->assertSame('recorded', $video->status);
        $this->assertNull($video->postsyncer);

        Http::assertNothingSent();
    }

    public function test_sets_running_before_api_calls(): void
    {
        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);

        $video = Video::factory()->for($workspace)->create([
            'status' => 'recorded',
            'publish_state' => 'queued',
            'language' => 'bn',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'captions' => ['facebook' => 'Hello reel'],
        ]);

        $seenRunning = false;
        Http::fake(function ($request) use ($video, &$seenRunning) {
            $video->refresh();
            if ($video->publish_state === 'running') {
                $seenRunning = true;
            }

            return Http::response(['id' => 1, 'status' => 'published'], 201);
        });

        $this->action->handle($video, [
            'platforms' => ['facebook'],
            'confirm_ask' => false,
        ]);

        $this->assertTrue($seenRunning);
    }
}
