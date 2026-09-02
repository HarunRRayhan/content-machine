<?php

namespace Tests\Feature\Api;

use App\Actions\ApiTokens\CreateWorkspaceApiTokenAction;
use App\Data\ApiTokens\CreateWorkspaceApiTokenData;
use App\Jobs\PublishVideoJob;
use App\Models\Idea;
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

    public function test_index_omits_heavy_fields_by_default(): void
    {
        Video::factory()->for($this->workspace)->create([
            'human_id' => 'BV-63',
            'number' => 63,
            'title' => 'Heavy deck',
            'script_markdown' => str_repeat('line\n', 40),
            'captions' => ['facebook' => 'long caption'],
            'deck_manifest' => [
                'engine' => 'reveal',
                'js' => str_repeat('x', 5000),
                'css' => 'body{}',
            ],
        ]);

        $this->acting()->getJson('/api/v1/videos')
            ->assertOk()
            ->assertJsonPath('data.0.human_id', 'BV-63')
            ->assertJsonPath('data.0.has_script', true)
            ->assertJsonPath('data.0.has_captions', true)
            ->assertJsonPath('data.0.has_deck', true)
            ->assertJsonMissingPath('data.0.script_markdown')
            ->assertJsonMissingPath('data.0.captions')
            ->assertJsonMissingPath('data.0.deck_manifest');
    }

    public function test_index_include_full_returns_heavy_fields(): void
    {
        Video::factory()->for($this->workspace)->create([
            'human_id' => 'BV-64',
            'number' => 64,
            'script_markdown' => '# spoken',
            'captions' => ['tiktok' => 'cap'],
            'deck_manifest' => ['engine' => 'reveal', 'js' => '1'],
        ]);

        $this->acting()->getJson('/api/v1/videos?include=full')
            ->assertOk()
            ->assertJsonPath('data.0.script_markdown', '# spoken')
            ->assertJsonPath('data.0.captions.tiktok', 'cap')
            ->assertJsonPath('data.0.deck_manifest.engine', 'reveal');
    }

    public function test_show_still_returns_full_video_payload(): void
    {
        Video::factory()->for($this->workspace)->create([
            'human_id' => 'BV-65',
            'number' => 65,
            'script_markdown' => '# show',
            'deck_manifest' => ['engine' => 'reveal', 'js' => 'deck'],
        ]);

        $this->acting()->getJson('/api/v1/videos/BV-65')
            ->assertOk()
            ->assertJsonPath('data.script_markdown', '# show')
            ->assertJsonPath('data.deck_manifest.js', 'deck');
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

    public function test_an_explicit_import_advances_the_generated_video_sequence(): void
    {
        $this->acting()->postJson('/api/v1/videos', [
            'human_id' => 'BV-1',
            'number' => 1,
            'title' => 'Imported video',
        ])->assertCreated();

        $this->acting()->postJson('/api/v1/videos', [
            'title' => 'Generated video',
        ])
            ->assertCreated()
            ->assertJsonPath('data.human_id', 'V-2');
    }

    public function test_store_rejects_an_idea_from_another_workspace(): void
    {
        $foreignIdea = Idea::factory()->for(Workspace::factory())->create([
            'kind' => 'video',
        ]);

        $this->acting()->postJson('/api/v1/videos', [
            'title' => 'Cross-workspace video',
            'idea_id' => $foreignIdea->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idea_id');

        $this->assertDatabaseMissing('videos', ['title' => 'Cross-workspace video']);
    }

    public function test_a_write_only_token_does_not_receive_the_video_script_or_deck(): void
    {
        $writeToken = (new CreateWorkspaceApiTokenAction)->handle(
            $this->workspace,
            User::factory()->create(),
            new CreateWorkspaceApiTokenData('write-only', ['videos:write']),
        )['plaintext'];

        $this->withToken($writeToken)->postJson('/api/v1/videos', [
            'title' => 'Private video',
            'body' => 'Private body',
            'script_markdown' => 'Private script',
            'deck_manifest' => ['js' => 'alert(1)'],
        ])
            ->assertCreated()
            ->assertJsonMissingPath('data.body')
            ->assertJsonMissingPath('data.script_markdown')
            ->assertJsonMissingPath('data.deck_manifest');
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

    public function test_update_cannot_forge_postsyncer_groups(): void
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
            ->assertUnprocessable()
            ->assertJsonValidationErrors('postsyncer');

        $this->assertNull(Video::query()->where('human_id', 'BV-12')->value('postsyncer'));
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

    public function test_update_cannot_forge_publish_state_or_error(): void
    {
        Video::factory()->for($this->workspace)->create([
            'human_id' => 'BV-57',
            'number' => 57,
            'title' => 'Already live',
            'status' => 'posted',
            'publish_state' => 'failed',
            'publish_error' => 'This video already has PostSyncer posts. Republish is not supported yet.',
        ]);

        $this->acting()->patchJson('/api/v1/videos/BV-57', [
            'publish_state' => 'succeeded',
            'publish_error' => null,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('publish_state');

        $this->assertSame('failed', Video::query()->where('human_id', 'BV-57')->value('publish_state'));
    }

    public function test_publish_dispatches_job_and_returns_queued_state(): void
    {
        Queue::fake();
        PostsyncerConfig::write($this->workspace, [
            'publish_enabled' => true,
            'video_publish_enabled' => true,
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

    public function test_publish_rejects_when_postsyncer_groups_already_exist(): void
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
            'human_id' => 'BV-92',
            'number' => 92,
            'status' => 'posted',
            'publish_state' => 'succeeded',
            'postsyncer' => [
                'groups' => [[
                    'post_id' => '133111',
                    'status' => 'PUBLISHED',
                    'platforms' => ['instagram'],
                    'language' => 'english',
                ]],
            ],
        ]);

        $this->acting()->postJson('/api/v1/videos/BV-92/publish', [
            'when' => '2026-08-29T21:30:00+06:00',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('publish');

        Queue::assertNothingPushed();
        $video = Video::query()->where('human_id', 'BV-92')->sole();
        $this->assertSame('succeeded', $video->publish_state);
        $this->assertNull($video->publish_error);
    }
}
