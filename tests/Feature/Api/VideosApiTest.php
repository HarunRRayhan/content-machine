<?php

namespace Tests\Feature\Api;

use App\Actions\ApiTokens\CreateWorkspaceApiTokenAction;
use App\Data\ApiTokens\CreateWorkspaceApiTokenData;
use App\Jobs\PublishVideoJob;
use App\Models\User;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VideosApiTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create();
        $this->token = (new CreateWorkspaceApiTokenAction)->handle(
            $this->workspace,
            User::factory()->create(),
            new CreateWorkspaceApiTokenData('test client'),
        )['plaintext'];
    }

    private function acting(): self
    {
        return $this->withToken($this->token);
    }

    public function test_index_lists_videos_filtered_by_status(): void
    {
        Video::factory()->for($this->workspace)->create(['status' => 'draft', 'number' => 1, 'human_id' => 'V-1']);
        Video::factory()->for($this->workspace)->create(['status' => 'posted', 'number' => 2, 'human_id' => 'V-2']);

        $this->acting()->getJson('/api/v1/videos')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->acting()->getJson('/api/v1/videos?status=posted')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.human_id', 'V-2');
    }

    public function test_store_imports_a_video_with_explicit_human_id(): void
    {
        $this->acting()->postJson('/api/v1/videos', [
            'human_id' => 'BV-53',
            'number' => 53,
            'title' => 'Load balancer vs reverse proxy',
            'language' => 'bn',
            'slug' => 'load-balancer-vs-reverse-proxy',
            'script_markdown' => '# script',
            'captions' => ['facebook' => 'hello'],
            'status' => 'posted',
        ])
            ->assertCreated()
            ->assertJsonPath('data.human_id', 'BV-53')
            ->assertJsonPath('data.script_markdown', '# script')
            ->assertJsonPath('data.captions.facebook', 'hello');

        // Idempotent re-import
        $this->acting()->postJson('/api/v1/videos', [
            'human_id' => 'BV-53',
            'number' => 53,
            'title' => 'Load balancer vs reverse proxy',
        ])
            ->assertOk()
            ->assertJsonPath('data.human_id', 'BV-53');

        $this->assertDatabaseCount('videos', 1);
    }

    public function test_show_and_patch_address_by_human_id(): void
    {
        Video::factory()->for($this->workspace)->create([
            'human_id' => 'BV-10',
            'number' => 10,
            'title' => 'Old title',
            'status' => 'draft',
        ]);

        $this->acting()->getJson('/api/v1/videos/BV-10')
            ->assertOk()
            ->assertJsonPath('data.title', 'Old title');

        $this->acting()->patchJson('/api/v1/videos/BV-10', [
            'title' => 'New title',
            'status' => 'ready',
            'script_markdown' => 'spoken lines',
        ])
            ->assertOk()
            ->assertJsonPath('data.title', 'New title')
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.script_markdown', 'spoken lines');
    }

    public function test_store_accepts_drive_urls(): void
    {
        $this->fakeAccessibleDriveLinks();

        $this->acting()->postJson('/api/v1/videos', [
            'human_id' => 'BV-54',
            'number' => 54,
            'title' => 'After HyperFrames',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'cover_drive_url' => 'https://drive.google.com/file/d/cover/view',
        ])
            ->assertCreated()
            ->assertJsonPath('data.human_id', 'BV-54')
            ->assertJsonPath('data.video_drive_url', 'https://drive.google.com/file/d/video/view')
            ->assertJsonPath('data.cover_drive_url', 'https://drive.google.com/file/d/cover/view');

        $this->assertDatabaseHas('videos', [
            'human_id' => 'BV-54',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'cover_drive_url' => 'https://drive.google.com/file/d/cover/view',
        ]);
    }

    public function test_patch_accepts_drive_urls(): void
    {
        $this->fakeAccessibleDriveLinks();

        Video::factory()->for($this->workspace)->create([
            'human_id' => 'BV-11',
            'number' => 11,
            'title' => 'Recorded cut',
            'status' => 'recorded',
        ]);

        $this->acting()->patchJson('/api/v1/videos/BV-11', [
            'video_drive_url' => 'https://drive.google.com/file/d/new-video/view',
            'cover_drive_url' => 'https://drive.google.com/file/d/new-cover/view',
        ])
            ->assertOk()
            ->assertJsonPath('data.video_drive_url', 'https://drive.google.com/file/d/new-video/view')
            ->assertJsonPath('data.cover_drive_url', 'https://drive.google.com/file/d/new-cover/view');

        $this->assertDatabaseHas('videos', [
            'human_id' => 'BV-11',
            'title' => 'Recorded cut',
            'video_drive_url' => 'https://drive.google.com/file/d/new-video/view',
            'cover_drive_url' => 'https://drive.google.com/file/d/new-cover/view',
        ]);
    }

    public function test_update_can_record_postsyncer_groups(): void
    {
        Video::factory()->for($this->workspace)->create([
            'human_id' => 'BV-12',
            'number' => 12,
            'title' => 'Scheduled reel',
            'status' => 'recorded',
        ]);

        $this->acting()->patchJson('/api/v1/videos/BV-12', [
            'status' => 'scheduled',
            'postsyncer' => [
                'groups' => [[
                    'post_id' => '132195',
                    'status' => 'SCHEDULED',
                    'scheduled_at' => '2026-08-27 17:45',
                    'platforms' => ['facebook', 'instagram', 'tiktok', 'youtube'],
                    'language' => 'bangla',
                ]],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.postsyncer.groups.0.post_id', '132195');
    }

    public function test_patch_rejects_a_private_drive_url(): void
    {
        $this->fakePrivateDriveLinks();

        Video::factory()->for($this->workspace)->create([
            'human_id' => 'BV-11',
            'number' => 11,
            'title' => 'Recorded cut',
            'status' => 'recorded',
        ]);

        $this->acting()->patchJson('/api/v1/videos/BV-11', [
            'video_drive_url' => 'https://drive.google.com/file/d/privateFile/view',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('video_drive_url');
    }

    public function test_media_url_check_reports_accessibility(): void
    {
        $this->fakeAccessibleDriveLinks();

        $this->acting()->postJson('/api/v1/media-urls/check', [
            'url' => 'https://drive.google.com/file/d/publicFile/view',
        ])
            ->assertOk()
            ->assertJsonPath('accessible', true)
            ->assertJsonPath('file_id', 'publicFile');
    }

    public function test_show_of_another_workspaces_video_is_not_found(): void
    {
        Video::factory()->for(Workspace::factory())->create([
            'human_id' => 'BV-1',
            'number' => 1,
        ]);

        $this->acting()->getJson('/api/v1/videos/BV-1')->assertNotFound();
    }

    public function test_publish_dispatches_job_and_returns_queued_state(): void
    {
        Queue::fake();
        PostsyncerConfig::write($this->workspace, [
            'publish_enabled' => true,
            'api_key' => 'test-api-key',
            'languages' => [
                'english' => ['workspace_id' => '853', 'platforms' => []],
            ],
        ]);

        Video::factory()->for($this->workspace)->create([
            'human_id' => 'BV-90',
            'number' => 90,
            'publish_state' => 'idle',
        ]);

        $this->acting()->postJson('/api/v1/videos/BV-90/publish', [
            'when' => '2026-08-29T21:30:00+06:00',
            'platforms' => ['facebook'],
            'confirm_ask' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.human_id', 'BV-90')
            ->assertJsonPath('data.publish_state', 'queued')
            ->assertJsonPath('data.publish_error', null);

        $video = Video::query()->where('human_id', 'BV-90')->sole();

        Queue::assertPushed(PublishVideoJob::class, function (PublishVideoJob $job) use ($video) {
            return $job->video->is($video)
                && $job->options['when'] === '2026-08-29T21:30:00+06:00'
                && $job->options['platforms'] === ['facebook']
                && $job->options['confirm_ask'] === true;
        });
    }

    public function test_publish_rejects_when_postsyncer_is_not_ready(): void
    {
        Queue::fake();

        Video::factory()->for($this->workspace)->create([
            'human_id' => 'BV-91',
            'number' => 91,
        ]);

        $this->acting()->postJson('/api/v1/videos/BV-91/publish', [
            'when' => '2026-08-29T21:30:00+06:00',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('publish');

        Queue::assertNothingPushed();
    }
}
