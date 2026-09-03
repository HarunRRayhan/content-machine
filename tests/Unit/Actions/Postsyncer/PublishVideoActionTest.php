<?php

namespace Tests\Unit\Actions\Postsyncer;

use App\Actions\Postsyncer\PublishVideoAction;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Postsyncer\MediaUrlResolver;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Postsyncer\PostsyncerException;
use App\Support\Postsyncer\VideoPublishPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
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
            'publish_enabled' => true,
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

    /**
     * @return array{key: string, media_urls: list<string>}
     */
    private function legacyGroup(Video $video, Workspace $workspace): array
    {
        $config = PostsyncerConfig::fromWorkspace($workspace);
        $groups = (new VideoPublishPlanner(new MediaUrlResolver))->plan(
            $video,
            $config,
            ['platforms' => ['facebook'], 'confirm_ask' => false],
        );
        $key = (string) (new \ReflectionMethod(PublishVideoAction::class, 'groupKey'))
            ->invoke($this->action, $config, $groups[0]);

        return [
            'key' => $key,
            'media_urls' => $groups[0]->mediaUrls,
        ];
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
                'count_stored' => 2,
            ], 200),
            'postsyncer.com/api/v1/posts' => Http::response([
                'id' => 42,
                'status' => 'published',
            ], 201),
            'postsyncer.com/api/v1/posts/42' => Http::response([
                'id' => 42,
                'workspace_id' => 15211,
                'content' => [[
                    'text' => 'FB reel caption',
                    'media' => [['id' => 915]],
                    'cover_image' => ['thumbnail' => 916],
                ]],
                'platforms' => [
                    ['platform' => 'facebook', 'account_id' => 100, 'settings' => [
                        'post_type' => 'REELS',
                        'caption' => 'FB reel caption',
                    ]],
                    ['platform' => 'instagram', 'account_id' => 101, 'settings' => [
                        'post_type' => 'REELS',
                        'caption' => 'IG reel caption',
                    ]],
                ],
                'status' => 'PUBLISHED',
            ], 200),
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

        Http::assertSentCount(3);
        $mediaRequest = Http::recorded(
            fn ($request): bool => $request->url() === 'https://postsyncer.com/api/v1/media/upload/url',
        )->first();
        $postRequest = Http::recorded(
            fn ($request): bool => $request->url() === 'https://postsyncer.com/api/v1/posts',
        )->first();
        $mediaKey = $mediaRequest[0]->header('Idempotency-Key')[0] ?? null;
        $postKey = $postRequest[0]->header('Idempotency-Key')[0] ?? null;
        $this->assertIsString($mediaKey);
        $this->assertIsString($postKey);
        $this->assertStringEndsWith(':media', $mediaKey);
        $this->assertStringEndsWith(':post', $postKey);
        $this->assertNotSame($mediaKey, $postKey);
    }

    public function test_worker_fails_closed_when_video_publishing_is_disabled(): void
    {
        Http::fake();

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        PostsyncerConfig::write($workspace, ['video_publish_enabled' => false]);
        $video = Video::factory()->for($workspace)->create([
            'status' => 'recorded',
            'language' => 'bn',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'captions' => ['facebook' => 'Disabled video publish'],
        ]);

        $this->action->handle($video, ['confirm_ask' => false]);

        $video->refresh();
        $this->assertSame('failed', $video->publish_state);
        $this->assertSame(PostsyncerConfig::VIDEO_PUBLISH_DISABLED_MESSAGE, $video->publish_error);
        Http::assertNothingSent();
    }

    public function test_create_polls_an_async_canonical_response_before_checkpointing(): void
    {
        $lookups = 0;
        Http::fake(function ($request) use (&$lookups) {
            if ($request->url() === 'https://postsyncer.com/api/v1/media/upload/url') {
                return Http::response([
                    'media' => [['id' => 915]],
                    'count_stored' => 1,
                ], 200);
            }

            if ($request->url() === 'https://postsyncer.com/api/v1/posts') {
                return Http::response(['id' => 42, 'status' => 'queued'], 201);
            }

            if ($request->url() === 'https://postsyncer.com/api/v1/posts/42') {
                $lookups++;

                if ($lookups === 1) {
                    return Http::response(['id' => 42, 'status' => 'PENDING'], 200);
                }

                return Http::response(['data' => [
                    'id' => 42,
                    'workspace_id' => 15211,
                    'content' => [['text' => 'Reel caption', 'media' => [['id' => 915]]]],
                    'platforms' => [['platform' => 'facebook', 'account_id' => 100, 'settings' => [
                        'post_type' => 'REELS', 'caption' => 'Reel caption',
                    ]]],
                    'status' => 'PUBLISHED',
                ]], 200);
            }

            return Http::response(['message' => 'Unexpected request'], 500);
        });

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $video = Video::factory()->for($workspace)->create([
            'status' => 'recorded',
            'language' => 'bn',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'captions' => ['facebook' => 'Reel caption'],
        ]);

        $this->action->handle($video, [
            'platforms' => ['facebook'],
            'confirm_ask' => false,
        ]);

        $video->refresh();
        $this->assertSame('succeeded', $video->publish_state);
        $this->assertSame(2, $lookups);
        Http::assertSentCount(4);
    }

    public function test_canonical_connection_failure_is_retried_without_recreating(): void
    {
        $lookups = 0;
        Http::fake(function ($request) use (&$lookups) {
            if ($request->url() === 'https://postsyncer.com/api/v1/media/upload/url') {
                return Http::response([
                    'media' => [['id' => 915]],
                    'count_stored' => 1,
                ], 200);
            }

            if ($request->url() === 'https://postsyncer.com/api/v1/posts') {
                return Http::response(['id' => 43, 'status' => 'published'], 201);
            }

            if ($request->url() === 'https://postsyncer.com/api/v1/posts/43') {
                $lookups++;

                if ($lookups < 3) {
                    throw new ConnectionException('connection refused');
                }

                return Http::response([
                    'id' => 43,
                    'workspace_id' => 15211,
                    'content' => [[
                        'text' => 'Reel caption',
                        'media' => [['id' => 915]],
                    ]],
                    'platforms' => [['platform' => 'facebook', 'account_id' => 100, 'settings' => [
                        'post_type' => 'REELS', 'caption' => 'Reel caption',
                    ]]],
                    'status' => 'PUBLISHED',
                ], 200);
            }

            return Http::response(['message' => 'Unexpected request'], 500);
        });

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $video = Video::factory()->for($workspace)->create([
            'status' => 'recorded',
            'language' => 'bn',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'captions' => ['facebook' => 'Reel caption'],
        ]);

        $this->action->handle($video, [
            'platforms' => ['facebook'],
            'confirm_ask' => false,
        ]);

        $video->refresh();
        $this->assertSame('succeeded', $video->publish_state);
        $this->assertSame(3, $lookups);
        $this->assertCount(1, Http::recorded(
            fn ($request): bool => $request->url() === 'https://postsyncer.com/api/v1/posts',
        ));
    }

    public function test_canonical_payload_mismatch_becomes_uncertain_without_recreating(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/media/upload/url' => Http::response([
                'media' => [['id' => 915]],
                'count_stored' => 1,
            ], 200),
            'postsyncer.com/api/v1/posts' => Http::response(['id' => 44, 'status' => 'published'], 201),
            'postsyncer.com/api/v1/posts/44' => Http::response([
                'id' => 44,
                'workspace_id' => 15211,
                'content' => [[
                    'text' => 'Wrong caption',
                    'media' => [['id' => 915]],
                ]],
                'platforms' => [['platform' => 'facebook', 'account_id' => 100, 'settings' => [
                    'post_type' => 'REELS', 'caption' => 'Reel caption',
                ]]],
                'status' => 'PUBLISHED',
            ], 200),
        ]);

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $video = Video::factory()->for($workspace)->create([
            'status' => 'recorded',
            'language' => 'bn',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'captions' => ['facebook' => 'Reel caption'],
        ]);

        $this->action->handle($video, [
            'platforms' => ['facebook'],
            'confirm_ask' => false,
        ]);

        $video->refresh();
        $this->assertSame('failed', $video->publish_state);
        $this->assertStringContainsString('outcome is uncertain', (string) $video->publish_error);
        $this->assertSame('uncertain', $video->publish_progress['state']);
        $this->assertNotNull($video->publish_progress['current']);
        $this->assertCount(1, Http::recorded(
            fn ($request): bool => $request->url() === 'https://postsyncer.com/api/v1/posts',
        ));
    }

    public function test_schedule_sets_status_scheduled(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/media/upload/url' => Http::response([
                'media' => [['id' => 915]],
                'count_stored' => 1,
            ], 200),
            'postsyncer.com/api/v1/posts' => Http::response([
                'id' => 99,
                'status' => 'scheduled',
                'scheduled_at' => '2026-08-26T09:12:00+06:00',
            ], 201),
            'postsyncer.com/api/v1/posts/99' => Http::response([
                'id' => 99,
                'workspace_id' => 15211,
                'content' => [[
                    'text' => 'Scheduled reel caption',
                    'media' => [['id' => 915]],
                ]],
                'platforms' => [['platform' => 'facebook', 'account_id' => 100, 'settings' => [
                    'post_type' => 'REELS',
                    'caption' => 'Scheduled reel caption',
                ]]],
                'status' => 'SCHEDULED',
                'scheduled_at' => '2026-08-26T09:12:00+06:00',
            ], 200),
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
                'count_stored' => 1,
            ], 200),
            'postsyncer.com/api/v1/posts' => Http::response([
                'id' => 99,
                'status' => 'scheduled',
                'scheduled_at' => '2026-08-26T09:12:00+06:00',
            ], 201),
            'postsyncer.com/api/v1/posts/99' => Http::response([
                'id' => 99,
                'workspace_id' => 15211,
                'content' => [[
                    'text' => 'Scheduled reel caption',
                    'media' => [['id' => 915]],
                ]],
                'platforms' => [['platform' => 'facebook', 'account_id' => 100, 'settings' => [
                    'post_type' => 'REELS',
                    'caption' => 'Scheduled reel caption',
                ]]],
                'status' => 'SCHEDULED',
                'scheduled_at' => '2026-08-26T09:12:00+06:00',
            ], 200),
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

    public function test_empty_media_upload_response_does_not_create_a_text_only_video(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/media/upload/url' => Http::response([
                'media' => [],
                'count_stored' => 0,
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
            'language' => 'bn',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'captions' => ['facebook' => 'Reel caption'],
        ]);

        $this->action->handle($video, [
            'platforms' => ['facebook'],
            'confirm_ask' => false,
        ]);

        $video->refresh();
        $this->assertSame('failed', $video->publish_state);
        $this->assertStringContainsString('incomplete media upload response', (string) $video->publish_error);
        $this->assertSame('recorded', $video->status);
        $this->assertNull($video->postsyncer);
        Http::assertNotSent(fn ($request) => $request->url() === 'https://postsyncer.com/api/v1/posts');
    }

    public function test_uncertain_media_upload_can_be_reconciled_without_uploading_again(): void
    {
        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $video = Video::factory()->for($workspace)->create([
            'status' => 'recorded',
            'language' => 'bn',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'cover_drive_url' => 'https://drive.google.com/file/d/cover/view',
            'captions' => ['facebook' => 'Caption'],
        ]);
        $uploadCalls = 0;

        Http::fake(function ($request) use (&$uploadCalls) {
            if (str_ends_with($request->url(), '/media/upload/url')) {
                $uploadCalls++;

                return $uploadCalls === 1
                    ? Http::response(['message' => 'temporary outage'], 503)
                    : Http::response(['message' => 'upload must not be retried'], 500);
            }

            if ($request->url() === 'https://postsyncer.com/api/v1/posts') {
                return Http::response(['id' => 42, 'status' => 'published'], 201);
            }

            return Http::response([
                'id' => 42,
                'workspace_id' => 15211,
                'content' => [[
                    'text' => 'Caption',
                    'media' => [['id' => 915]],
                    'cover_image' => ['thumbnail' => 916],
                ]],
                'platforms' => [['platform' => 'facebook', 'account_id' => 100, 'settings' => [
                    'post_type' => 'REELS', 'caption' => 'Caption',
                ]]],
                'status' => 'PUBLISHED',
            ], 200);
        });

        try {
            $this->action->handle($video, ['confirm_ask' => false]);
        } catch (PostsyncerException $exception) {
            $this->assertTrue($exception->retryable);
        }

        $video->refresh();
        $this->assertSame('uncertain', $video->publish_progress['state']);
        $this->assertSame('uploading', $video->publish_progress['current']['phase']);

        $this->action->reconcileMedia($video, [915, 916]);

        $video->refresh();
        $this->assertSame('failed', $video->publish_state);
        $this->assertSame('failed', $video->publish_progress['state']);
        $this->assertSame('retryable', $video->publish_progress['current']['phase']);
        $this->assertSame(['915', '916'], $video->publish_progress['current']['media_ids']);

        $this->action->handle($video, ['confirm_ask' => false]);

        $video->refresh();
        $this->assertSame('succeeded', $video->publish_state);
        $this->assertSame('42', $video->postsyncer['groups'][0]['post_id']);
        $this->assertSame(1, $uploadCalls);
    }

    public function test_plan_drift_can_be_recovered_from_the_stored_payload_snapshot(): void
    {
        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $video = Video::factory()->for($workspace)->create([
            'status' => 'recorded',
            'language' => 'bn',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'captions' => ['facebook' => 'Original caption'],
        ]);
        $changed = false;

        Http::fake(function ($request) use ($video, &$changed) {
            if (str_ends_with($request->url(), '/media/upload/url')) {
                return Http::response([
                    'media' => [['id' => 915]],
                    'count_stored' => 1,
                ], 200);
            }

            if ($request->url() === 'https://postsyncer.com/api/v1/posts' && ! $changed) {
                Video::query()->whereKey($video->id)->update([
                    'captions' => ['facebook' => 'Changed caption'],
                ]);
                $changed = true;

                return Http::response(['id' => 42, 'status' => 'published'], 201);
            }

            if ($request->url() === 'https://postsyncer.com/api/v1/posts/42') {
                return Http::response([
                    'id' => 42,
                    'workspace_id' => 15211,
                    'content' => [['text' => 'Original caption', 'media' => [['id' => 915]]]],
                    'platforms' => [['platform' => 'facebook', 'account_id' => 100, 'settings' => [
                        'post_type' => 'REELS', 'caption' => 'Original caption',
                    ]]],
                    'status' => 'PUBLISHED',
                ], 200);
            }

            return Http::response(['message' => 'Unexpected request'], 500);
        });

        $this->action->handle($video, ['confirm_ask' => false]);
        $video->refresh();

        $this->assertSame('failed', $video->publish_state);
        $this->assertNull($video->postsyncer);
        $this->assertCount(1, $video->publish_progress['completed_groups']);

        $this->action->recoverPlanDrift($video);

        $video->refresh();
        $this->assertSame('succeeded', $video->publish_state);
        $this->assertSame('posted', $video->status);
        $this->assertTrue($video->publish_progress['plan_drift_recovered']);
        $this->assertSame('42', $video->postsyncer['groups'][0]['post_id']);
    }

    public function test_reconciliation_replaces_a_duplicate_completed_checkpoint(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/media/upload/url' => Http::response([
                'media' => [['id' => 915]],
                'count_stored' => 1,
            ], 200),
            'postsyncer.com/api/v1/posts' => Http::response([
                'message' => 'gateway timeout',
            ], 500),
        ]);

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $video = Video::factory()->for($workspace)->create([
            'status' => 'recorded',
            'language' => 'bn',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'captions' => ['facebook' => 'Reel caption'],
        ]);
        $options = [
            'platforms' => ['facebook'],
            'confirm_ask' => false,
        ];

        $this->action->handle($video, $options);
        $video->refresh();

        $progress = $video->publish_progress;
        $progress['completed_groups'][] = [
            'index' => 0,
            'group_key' => $progress['current']['group_key'],
            'post_id' => '98',
            'status' => 'PUBLISHED',
            'scheduled_at' => null,
            'platforms' => ['facebook'],
            'language' => 'bangla',
        ];
        $video->forceFill(['publish_progress' => $progress])->save();

        Http::fake([
            'postsyncer.com/api/v1/posts/99' => Http::response([
                'id' => 99,
                'workspace_id' => 15211,
                'content' => [['text' => 'Reel caption', 'media' => [['id' => 915]]]],
                'platforms' => [['platform' => 'facebook', 'account_id' => 100, 'settings' => [
                    'post_type' => 'REELS', 'caption' => 'Reel caption',
                ]]],
                'status' => 'PUBLISHED',
            ], 200),
        ]);

        $this->action->reconcile($video, 99);

        $video->refresh();
        $this->assertCount(1, $video->publish_progress['completed_groups']);
        $this->assertSame('99', $video->publish_progress['completed_groups'][0]['post_id']);

        Http::fake([
            'postsyncer.com/api/v1/posts' => Http::response([
                'message' => 'must not create again',
            ], 500),
        ]);
        $this->action->handle($video, $options);

        $video->refresh();
        $this->assertSame('succeeded', $video->publish_state);
        Http::assertNothingSent();
    }

    public function test_failure_keeps_a_newer_media_checkpoint_over_stale_local_progress(): void
    {
        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $video = Video::factory()->for($workspace)->create([
            'status' => 'recorded',
            'language' => 'bn',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'captions' => ['facebook' => 'Reel caption'],
        ]);

        Http::fake(function ($request) use ($video) {
            if (str_ends_with($request->url(), '/media/upload/url')) {
                return Http::response([
                    'media' => [['id' => 915]],
                    'count_stored' => 1,
                ], 200);
            }

            if ($request->url() === 'https://postsyncer.com/api/v1/posts') {
                $latestVideo = $video->fresh();
                $progress = $latestVideo->publish_progress;
                $progress['current']['media_ids'] = [916];
                $latestVideo->forceFill(['publish_progress' => $progress])->save();

                return Http::response(['message' => 'invalid account'], 422);
            }

            return Http::response(['message' => 'Unexpected request'], 500);
        });

        $this->action->handle($video, ['confirm_ask' => false]);

        $video->refresh();
        $this->assertSame('failed', $video->publish_state);
        $this->assertSame('retryable', $video->publish_progress['current']['phase']);
        $this->assertSame([916], $video->publish_progress['current']['media_ids']);
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
                'count_stored' => 1,
            ], 200),
            'postsyncer.com/api/v1/posts' => Http::response([
                'id' => 77,
                'status' => 'published',
            ], 201),
            'postsyncer.com/api/v1/posts/77' => Http::response([
                'id' => 77,
                'workspace_id' => 15211,
                'content' => [[
                    'text' => 'Retry after empty plan',
                    'media' => [['id' => 915]],
                ]],
                'platforms' => [['platform' => 'facebook', 'account_id' => 100, 'settings' => [
                    'post_type' => 'REELS',
                    'caption' => 'Retry after empty plan',
                ]]],
                'status' => 'PUBLISHED',
            ], 200),
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

    public function test_missing_account_mapping_is_retriable_without_uploading_media(): void
    {
        $ready = false;
        Http::fake(function ($request) use (&$ready) {
            if (! $ready) {
                return Http::response(['message' => 'Unexpected request'], 500);
            }

            if ($request->url() === 'https://postsyncer.com/api/v1/media/upload/url') {
                return Http::response([
                    'media' => [['id' => 915]],
                    'count_stored' => 1,
                ], 200);
            }

            if ($request->url() === 'https://postsyncer.com/api/v1/posts') {
                return Http::response(['id' => 42, 'status' => 'published'], 201);
            }

            if ($request->url() === 'https://postsyncer.com/api/v1/posts/42') {
                return Http::response([
                    'id' => 42,
                    'workspace_id' => 15211,
                    'content' => [['text' => 'Reel caption', 'media' => [['id' => 915]]]],
                    'platforms' => [['platform' => 'facebook', 'account_id' => 100, 'settings' => [
                        'post_type' => 'REELS', 'caption' => 'Reel caption',
                    ]]],
                    'status' => 'PUBLISHED',
                ], 200);
            }

            return Http::response(['message' => 'Unexpected request'], 500);
        });

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        PostsyncerConfig::write($workspace, [
            'languages' => [
                'bangla' => [
                    'platforms' => [
                        'facebook' => ['account_id' => null],
                    ],
                ],
            ],
        ]);
        $video = Video::factory()->for($workspace)->create([
            'status' => 'recorded',
            'language' => 'bn',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'captions' => ['facebook' => 'Reel caption'],
        ]);
        $options = [
            'platforms' => ['facebook'],
            'confirm_ask' => false,
        ];

        $this->action->handle($video, $options);

        $video->refresh();
        $this->assertSame('failed', $video->publish_state);
        $this->assertStringContainsString('No account id mapped', (string) $video->publish_error);
        $this->assertSame('failed', $video->publish_progress['state']);
        $this->assertNull($video->publish_progress['current']);
        $this->assertTrue($video->canRetryPublish());
        Http::assertNothingSent();

        PostsyncerConfig::write($workspace, [
            'languages' => [
                'bangla' => [
                    'platforms' => [
                        'facebook' => ['account_id' => 100],
                    ],
                ],
            ],
        ]);
        $ready = true;

        $this->action->handle($video, $options);

        $video->refresh();
        $this->assertSame('succeeded', $video->publish_state);
        $this->assertSame('42', $video->postsyncer['groups'][0]['post_id']);
    }

    public function test_legacy_missing_account_checkpoint_reuses_registered_media_after_mapping_fix(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/media/upload/url' => Http::response([
                'media' => [['id' => 999]],
                'count_stored' => 1,
            ], 200),
            'postsyncer.com/api/v1/posts' => Http::response([
                'id' => 42,
                'status' => 'published',
            ], 201),
            'postsyncer.com/api/v1/posts/42' => Http::response([
                'id' => 42,
                'workspace_id' => 15211,
                'content' => [['text' => 'Reel caption', 'media' => [['id' => 915]]]],
                'platforms' => [['platform' => 'facebook', 'account_id' => 100, 'settings' => [
                    'post_type' => 'REELS', 'caption' => 'Reel caption',
                ]]],
                'status' => 'PUBLISHED',
            ], 200),
        ]);

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        PostsyncerConfig::write($workspace, [
            'languages' => [
                'bangla' => [
                    'platforms' => [
                        'facebook' => ['account_id' => null],
                    ],
                ],
            ],
        ]);
        $video = Video::factory()->for($workspace)->create([
            'status' => 'recorded',
            'language' => 'bn',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'captions' => ['facebook' => 'Reel caption'],
        ]);
        $legacy = $this->legacyGroup($video, $workspace);
        PostsyncerConfig::write($workspace, [
            'languages' => [
                'bangla' => [
                    'platforms' => [
                        'facebook' => ['account_id' => 100],
                    ],
                ],
            ],
        ]);
        $video->forceFill([
            'publish_state' => 'failed',
            'publish_error' => 'PostSyncer create outcome is uncertain. Reconcile PostSyncer before retrying. No account id mapped for platform facebook.',
            'publish_progress' => [
                'version' => 1,
                'operation_id' => 'operation-1',
                'run_token' => 'run-1',
                'options' => ['when' => null, 'confirm_ask' => false, 'platforms' => ['facebook']],
                'plan_hash' => 'legacy-plan',
                'planned_groups' => [['index' => 0, 'group_key' => $legacy['key']]],
                'completed_groups' => [],
                'current' => [
                    'index' => 0,
                    'group_key' => $legacy['key'],
                    'phase' => 'creating',
                    'idempotency_key' => 'legacy-request',
                    'media_ids' => [915],
                    'media_urls' => $legacy['media_urls'],
                ],
                'state' => 'uncertain',
            ],
        ])->save();

        $this->action->handle($video, ['platforms' => ['facebook'], 'confirm_ask' => false]);

        $video->refresh();
        $this->assertSame('succeeded', $video->publish_state);
        $this->assertSame('42', $video->postsyncer['groups'][0]['post_id']);
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/media/upload/url'));
        Http::assertSent(fn ($request): bool => $request->url() === 'https://postsyncer.com/api/v1/posts'
            && $request['content'][0]['media'] === [915]);
    }

    public function test_legacy_missing_account_checkpoint_is_not_rebound_when_media_changes(): void
    {
        Http::fake();

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        PostsyncerConfig::write($workspace, [
            'languages' => [
                'bangla' => [
                    'platforms' => [
                        'facebook' => ['account_id' => null],
                    ],
                ],
            ],
        ]);
        $video = Video::factory()->for($workspace)->create([
            'status' => 'recorded',
            'language' => 'bn',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'captions' => ['facebook' => 'Reel caption'],
        ]);
        $legacy = $this->legacyGroup($video, $workspace);
        PostsyncerConfig::write($workspace, [
            'languages' => [
                'bangla' => [
                    'platforms' => [
                        'facebook' => ['account_id' => 100],
                    ],
                ],
            ],
        ]);
        $video->forceFill([
            'publish_state' => 'failed',
            'publish_error' => 'No account id mapped for platform facebook.',
            'publish_progress' => [
                'version' => 1,
                'operation_id' => 'operation-1',
                'run_token' => 'run-1',
                'options' => ['when' => null, 'confirm_ask' => false, 'platforms' => ['facebook']],
                'plan_hash' => 'legacy-plan',
                'planned_groups' => [['index' => 0, 'group_key' => $legacy['key']]],
                'completed_groups' => [],
                'current' => [
                    'index' => 0,
                    'group_key' => $legacy['key'],
                    'phase' => 'creating',
                    'idempotency_key' => 'legacy-request',
                    'media_ids' => [915],
                    'media_urls' => ['https://example.com/a-different-video.mp4'],
                ],
                'state' => 'uncertain',
            ],
        ])->save();

        $this->action->handle($video, ['platforms' => ['facebook'], 'confirm_ask' => false]);

        $video->refresh();
        $this->assertSame('failed', $video->publish_state);
        $this->assertStringContainsString('cannot be repaired safely', (string) $video->publish_error);
        $this->assertSame('uncertain', $video->publish_progress['state']);
        Http::assertNothingSent();
    }

    public function test_create_reconciliation_rejects_an_uncertain_media_upload(): void
    {
        Http::fake();

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $video = Video::factory()->for($workspace)->create([
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

        $exception = null;
        try {
            $this->action->reconcile($video, 99);
        } catch (PostsyncerException $thrown) {
            $exception = $thrown;
        }

        $this->assertInstanceOf(PostsyncerException::class, $exception);
        $this->assertStringContainsString('uncertain postsyncer create', strtolower((string) $exception?->getMessage()));
        Http::assertNothingSent();
    }

    public function test_retryable_media_checkpoint_without_plan_metadata_is_not_reset(): void
    {
        Http::fake();

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $video = Video::factory()->for($workspace)->create([
            'status' => 'recorded',
            'language' => 'bn',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'captions' => ['facebook' => 'Reel caption'],
            'publish_state' => 'failed',
            'publish_error' => 'PostSyncer create was rejected.',
            'publish_progress' => [
                'version' => 1,
                'operation_id' => 'operation-1',
                'run_token' => 'run-1',
                'options' => ['when' => null, 'confirm_ask' => false, 'platforms' => ['facebook']],
                'plan_hash' => null,
                'planned_groups' => [],
                'completed_groups' => [],
                'current' => [
                    'index' => 0,
                    'group_key' => 'group-1',
                    'phase' => 'retryable',
                    'idempotency_key' => 'request-1',
                    'media_ids' => [915],
                ],
                'state' => 'failed',
            ],
        ]);

        $this->action->handle($video, ['platforms' => ['facebook'], 'confirm_ask' => false]);

        $video->refresh();
        $this->assertSame('failed', $video->publish_state);
        $this->assertStringContainsString('no plan metadata', (string) $video->publish_error);
        $this->assertSame('retryable', $video->publish_progress['current']['phase']);
        $this->assertSame([915], $video->publish_progress['current']['media_ids']);
        Http::assertNothingSent();
    }

    public function test_lost_create_response_records_an_uncertain_current_group(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/media/upload/url' => Http::response([
                'media' => [['id' => 915]],
                'count_stored' => 1,
            ], 200),
            'postsyncer.com/api/v1/posts' => Http::response([
                'message' => 'gateway timeout',
            ], 500),
        ]);

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);

        $video = Video::factory()->for($workspace)->create([
            'status' => 'recorded',
            'language' => 'bn',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'captions' => ['facebook' => 'Reel caption'],
        ]);

        $this->action->handle($video, [
            'platforms' => ['facebook'],
            'confirm_ask' => false,
        ]);

        $video->refresh();
        $this->assertSame('failed', $video->publish_state);
        $this->assertStringContainsString('outcome is uncertain', (string) $video->publish_error);
        $this->assertSame('uncertain', $video->publish_progress['state']);
        $this->assertSame(0, $video->publish_progress['current']['index']);
        $this->assertSame('creating', $video->publish_progress['current']['phase']);
        $this->assertNull($video->postsyncer);
    }

    public function test_confirm_failed_reconciliation_requires_the_explicit_flag(): void
    {
        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);

        $video = Video::factory()->for($workspace)->create([
            'status' => 'recorded',
            'language' => 'bn',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'captions' => ['facebook' => 'Scheduled reel caption'],
        ]);
        $options = [
            'when' => '2026-08-26T09:12:00+06:00',
            'platforms' => ['facebook'],
            'confirm_ask' => false,
        ];

        Http::fake([
            'postsyncer.com/api/v1/media/upload/url' => Http::response([
                'media' => [['id' => 915]],
                'count_stored' => 1,
            ], 200),
            'postsyncer.com/api/v1/posts' => Http::response(['message' => 'gateway timeout'], 500),
        ]);
        $this->action->handle($video, $options);

        Http::fake([
            'postsyncer.com/api/v1/posts/99' => Http::response([
                'id' => 99,
                'workspace_id' => 15211,
                'content' => [['text' => 'Scheduled reel caption', 'media' => [['id' => 915]]]],
                'platforms' => [['platform' => 'facebook', 'account_id' => 100, 'settings' => [
                    'post_type' => 'REELS', 'caption' => 'Scheduled reel caption',
                ]]],
                'status' => 'FAILED',
                'scheduled_at' => '2026-08-26T09:12:00+06:00',
            ], 200),
        ]);

        try {
            $this->action->reconcile($video, 99);
            $this->fail('Reconciliation should require explicit confirmation for FAILED.');
        } catch (PostsyncerException $exception) {
            $this->assertStringContainsString('not in a publishable state', $exception->getMessage());
        }

        $this->action->reconcile($video, 99, true);

        $video->refresh();
        $group = $video->publish_progress['completed_groups'][0];
        $this->assertSame('FAILED', $group['status']);
        $this->assertSame('FAILED', $group['remote_status']);
        $this->assertTrue($group['operator_confirmed']);

        Http::fake([
            'postsyncer.com/api/v1/posts' => Http::response(['message' => 'must not create again'], 500),
        ]);
        $this->action->handle($video, $options);

        $video->refresh();
        $this->assertSame('succeeded', $video->publish_state);
        $this->assertSame('scheduled', $video->status);
        $this->assertSame('FAILED', $video->postsyncer['groups'][0]['status']);
        Http::assertNothingSent();
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

            if (str_ends_with($request->url(), '/media/upload/url')) {
                return Http::response([
                    'media' => [['id' => 915]],
                    'count_stored' => 1,
                ], 200);
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
