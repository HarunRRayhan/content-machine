<?php

namespace Tests\Feature\Videos;

use App\Models\Attachment;
use App\Models\Idea;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\Video;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class VideosControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsWorkspaceMember(): array
    {
        $workspace = Workspace::factory()->create();
        $team = $workspace->team;
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->members()->attach($user->id, ['role' => 'owner']);

        $this->actingAs($user);

        return [$user, $workspace];
    }

    public function test_guests_cannot_view_videos()
    {
        $this->get(route('videos.index'))->assertRedirect(route('login'));
    }

    public function test_legacy_dashboard_index_redirects_to_videos(): void
    {
        $this->actingAsWorkspaceMember();

        $this->get('/dashboard/videos')->assertRedirect('/videos');
    }

    public function test_index_only_lists_the_current_workspaces_videos()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        $mine = Video::factory()->for($workspace)->create([
            'title' => 'Mine',
            'status' => 'pending',
        ]);

        $otherWorkspace = Workspace::factory()->create();
        Video::factory()->for($otherWorkspace)->create(['title' => 'Not mine']);

        $this->get(route('videos.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('videos/index')
                ->has('items.data', 1)
                ->where('items.data.0.type', 'video')
                ->where('items.data.0.id', $mine->id)
                ->where('filters.status', 'pending')
            );
    }

    public function test_index_defaults_to_pending_tab_when_status_is_missing(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        Video::factory()->for($workspace)->create(['title' => 'Pending one', 'status' => 'pending']);
        Video::factory()->for($workspace)->create(['title' => 'Ready one', 'status' => 'ready']);

        $this->get(route('videos.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('videos/index')
                ->has('items.data', 1)
                ->where('items.data.0.title', 'Pending one')
                ->where('filters.status', 'pending')
            );
    }

    public function test_index_ideation_tab_orders_ideas_by_score_desc(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        Idea::factory()->for($workspace)->create([
            'kind' => 'video',
            'status' => 'open',
            'title' => 'Cold idea',
            'score' => 210,
        ]);
        Idea::factory()->for($workspace)->create([
            'kind' => 'video',
            'status' => 'open',
            'title' => 'Hot idea',
            'score' => 910,
        ]);
        Idea::factory()->for($workspace)->create([
            'kind' => 'video',
            'status' => 'open',
            'title' => 'Mid idea',
            'score' => 620,
        ]);
        Idea::factory()->for($workspace)->create([
            'kind' => 'video',
            'status' => 'open',
            'title' => 'Unscored idea',
            'score' => null,
        ]);

        $this->get(route('videos.index', ['status' => 'ideation']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('videos/index')
                ->has('items.data', 4)
                ->where('items.data.0.title', 'Hot idea')
                ->where('items.data.1.title', 'Mid idea')
                ->where('items.data.2.title', 'Cold idea')
                ->where('items.data.3.title', 'Unscored idea')
            );
    }

    public function test_index_orders_videos_by_number_desc_not_created_at(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        Video::factory()->for($workspace)->create([
            'human_id' => 'BV-65',
            'number' => 65,
            'title' => 'Older import, higher number',
            'status' => 'pending',
            'created_at' => now()->subDays(2),
        ]);
        Video::factory()->for($workspace)->create([
            'human_id' => 'BV-67',
            'number' => 67,
            'title' => 'Newest import',
            'status' => 'pending',
            'created_at' => now(),
        ]);
        Video::factory()->for($workspace)->create([
            'human_id' => 'BV-62',
            'number' => 62,
            'title' => 'Oldest import, lower number',
            'status' => 'pending',
            'created_at' => now()->subDays(5),
        ]);

        $this->get(route('videos.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('videos/index')
                ->has('items.data', 3)
                ->where('items.data.0.human_id', 'BV-67')
                ->where('items.data.1.human_id', 'BV-65')
                ->where('items.data.2.human_id', 'BV-62')
            );
    }

    public function test_index_ideation_tab_lists_open_video_ideas(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        $idea = Idea::factory()->for($workspace)->create([
            'kind' => 'video',
            'status' => 'open',
            'title' => 'Video idea',
            'score' => 850,
            'trend' => 'seasonal',
        ]);

        Idea::factory()->for($workspace)->promoted()->create(['kind' => 'video', 'title' => 'Promoted']);
        Idea::factory()->for($workspace)->create(['kind' => 'post', 'status' => 'open', 'title' => 'Post idea']);

        $otherWorkspace = Workspace::factory()->create();
        Idea::factory()->for($otherWorkspace)->create(['kind' => 'video', 'status' => 'open']);

        $this->get(route('videos.index', ['status' => 'ideation']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('videos/index')
                ->has('items.data', 1)
                ->where('items.data.0.type', 'idea')
                ->where('items.data.0.id', $idea->id)
                ->where('items.data.0.title', 'Video idea')
                ->where('items.data.0.score', 850)
                ->where('items.data.0.trend', 'seasonal')
                ->where('filters.status', 'ideation')
            );
    }

    public function test_index_exposes_counts_for_all_status_tabs(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        Video::factory()->for($workspace)->count(2)->create(['status' => 'pending']);
        Video::factory()->for($workspace)->create(['status' => 'ready']);
        Video::factory()->for($workspace)->create(['status' => 'recorded']);
        Idea::factory()->for($workspace)->count(3)->create(['kind' => 'video', 'status' => 'open']);

        $this->get(route('videos.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('counts.ideation', 3)
                ->where('counts.draft', 0)
                ->where('counts.pending', 2)
                ->where('counts.ready', 1)
                ->where('counts.recorded', 1)
                ->where('counts.scheduled', 0)
                ->where('counts.posted', 0)
                ->where('counts.archived', 0)
                ->where('counts.dropped', 0)
            );
    }

    public function test_index_filters_videos_by_status_tab(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        Video::factory()->for($workspace)->create(['title' => 'Ready one', 'status' => 'ready']);
        Video::factory()->for($workspace)->create(['title' => 'Pending one', 'status' => 'pending']);

        $this->get(route('videos.index', ['status' => 'ready']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('videos/index')
                ->has('items.data', 1)
                ->where('items.data.0.type', 'video')
                ->where('items.data.0.title', 'Ready one')
                ->where('filters.status', 'ready')
            );
    }

    public function test_show_renders_a_video_in_the_current_workspace()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        $video = Video::factory()->for($workspace)->create(['title' => 'Hello video']);

        $this->get(route('videos.show', $video))
            ->assertInertia(fn (Assert $page) => $page
                ->component('videos/show')
                ->where('video.id', $video->id)
                ->where('video.title', 'Hello video')
                ->where('video.publish_state', $video->publish_state)
                ->where('video.postsyncer_ready', false)
                ->has('video.needs_confirm_ask')
                ->has('video.postsyncer')
            );
    }

    public function test_show_resolves_a_prefixed_human_id_on_the_short_url(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        $video = Video::factory()->for($workspace)->create([
            'title' => 'Custom id video',
            'human_id' => 'BV-46',
            'number' => 46,
        ]);

        $this->get('/videos/BV-46')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('videos/show')
                ->where('video.id', $video->id)
                ->where('video.human_id', 'BV-46')
                ->where('video.title', 'Custom id video')
            );
    }

    public function test_legacy_dashboard_show_redirects_to_videos_show(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        Video::factory()->for($workspace)->create([
            'title' => 'Dashboard custom id',
            'human_id' => 'V-12',
            'number' => 12,
        ]);

        $this->get('/dashboard/videos/V-12')->assertRedirect('/videos/V-12');
    }

    public function test_short_url_still_resolves_a_numeric_database_id(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        $video = Video::factory()->for($workspace)->create(['title' => 'Numeric id video']);

        $this->get('/videos/'.$video->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('videos/show')
                ->where('video.id', $video->id)
            );
    }

    public function test_short_url_404s_for_a_prefixed_id_in_another_workspace(): void
    {
        $this->actingAsWorkspaceMember();

        $otherWorkspace = Workspace::factory()->create();
        Video::factory()->for($otherWorkspace)->create(['human_id' => 'BV-46', 'number' => 46]);

        $this->get('/videos/BV-46')->assertNotFound();
    }

    public function test_index_filters_by_status_and_exposes_content_flags()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        Video::factory()->for($workspace)->create([
            'title' => 'Ready one',
            'status' => 'ready',
            'script_markdown' => '# Hook',
            'captions' => [
                'main' => [
                    'tiktok' => ['title' => 'T', 'caption' => 'C', 'first_comment' => ''],
                ],
            ],
        ]);
        Video::factory()->for($workspace)->create([
            'title' => 'Pending one',
            'status' => 'pending',
        ]);

        $this->get(route('videos.index', ['status' => 'ready']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('videos/index')
                ->has('items.data', 1)
                ->where('items.data.0.title', 'Ready one')
                ->where('items.data.0.has_script', true)
                ->where('items.data.0.has_captions', true)
                ->where('filters.status', 'ready')
            );
    }

    public function test_show_includes_normalized_script_and_captions()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        $video = Video::factory()->for($workspace)->create([
            'title' => 'Rich video',
            'script_markdown' => "## Hook\n\nSay this.",
            'captions' => [
                'Part 1' => [
                    'tiktok' => [
                        'title' => 'Hook title',
                        'caption' => 'Hook body',
                        'first_comment' => 'more',
                        'images' => [],
                        'thread' => [],
                    ],
                ],
            ],
        ]);

        $this->get(route('videos.show', $video))
            ->assertInertia(fn (Assert $page) => $page
                ->component('videos/show')
                ->where('video.script_markdown', "## Hook\n\nSay this.")
                ->where('video.captions.0.part', 'Part 1')
                ->where('video.captions.0.platforms.0.name', 'tiktok')
                ->where('video.captions.0.platforms.0.title', 'Hook title')
                ->where('video.has_deck', false)
            );
    }

    public function test_show_404s_for_a_video_in_a_different_workspace()
    {
        $this->actingAsWorkspaceMember();

        $otherWorkspace = Workspace::factory()->create();
        $video = Video::factory()->for($otherWorkspace)->create();

        $this->get(route('videos.show', $video))->assertNotFound();
    }

    public function test_update_edits_the_video()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $video = Video::factory()->for($workspace)->create(['title' => 'Old']);

        $response = $this->patch(route('videos.update', $video), [
            'title' => 'New',
            'body' => 'Updated body.',
        ]);

        $response->assertRedirect(route('videos.show', $video));

        $this->assertDatabaseHas('videos', [
            'id' => $video->id,
            'title' => 'New',
            'body' => 'Updated body.',
        ]);
    }

    public function test_update_rejects_a_private_drive_url(): void
    {
        $this->fakePrivateDriveLinks();

        [, $workspace] = $this->actingAsWorkspaceMember();
        $video = Video::factory()->for($workspace)->create(['title' => 'Recorded']);

        $this->from(route('videos.show', $video))
            ->patch(route('videos.update', $video), [
                'title' => 'Recorded',
                'video_drive_url' => 'https://drive.google.com/file/d/privateFile/view',
            ])
            ->assertRedirect(route('videos.show', $video))
            ->assertSessionHasErrors('video_drive_url');
    }

    public function test_media_url_check_is_available_on_the_dashboard(): void
    {
        $this->fakeAccessibleDriveLinks();
        $this->actingAsWorkspaceMember();

        $this->postJson(route('media-urls.check'), [
            'url' => 'https://drive.google.com/file/d/publicFile/view',
        ])
            ->assertOk()
            ->assertJsonPath('accessible', true)
            ->assertJsonPath('file_id', 'publicFile');
    }

    public function test_update_404s_for_a_video_in_a_different_workspace()
    {
        $this->actingAsWorkspaceMember();

        $otherWorkspace = Workspace::factory()->create();
        $video = Video::factory()->for($otherWorkspace)->create();

        $this->patch(route('videos.update', $video), ['title' => 'Nope'])->assertNotFound();
    }

    public function test_show_exposes_session_media_urls_for_attached_images(): void
    {
        Storage::fake('scratchpad');
        [, $workspace] = $this->actingAsWorkspaceMember();

        $video = Video::factory()->for($workspace)->create(['title' => 'With cover']);
        $media = MediaAsset::factory()->for($workspace)->create([
            'disk' => 'scratchpad',
            'path' => $workspace->id.'/cover.png',
            'original_filename' => 'cover.png',
            'mime' => 'image/png',
        ]);
        Storage::disk('scratchpad')->put($media->path, 'png-bytes');
        Attachment::factory()->for($video, 'attachable')->for($media)->create([
            'role' => 'image',
        ]);

        $expectedUrl = route('videos.media', [$video, $media]);

        $this->get(route('videos.show', $video))
            ->assertInertia(fn (Assert $page) => $page
                ->component('videos/show')
                ->where('video.images.0.url', $expectedUrl)
                ->where('video.images.0.filename', 'cover.png')
            );
    }

    public function test_media_streams_an_attached_image_for_the_current_workspace(): void
    {
        Storage::fake('scratchpad');
        [, $workspace] = $this->actingAsWorkspaceMember();

        $video = Video::factory()->for($workspace)->create();
        $media = MediaAsset::factory()->for($workspace)->create([
            'disk' => 'scratchpad',
            'path' => $workspace->id.'/view.jpg',
            'original_filename' => 'view.jpg',
            'mime' => 'image/jpeg',
        ]);
        Storage::disk('scratchpad')->put($media->path, 'jpeg-bytes');
        Attachment::factory()->for($video, 'attachable')->for($media)->create();

        $this->get(route('videos.media', [$video, $media]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_media_404s_for_an_asset_in_a_different_workspace(): void
    {
        Storage::fake('scratchpad');
        $this->actingAsWorkspaceMember();

        $otherWorkspace = Workspace::factory()->create();
        $otherVideo = Video::factory()->for($otherWorkspace)->create();
        $media = MediaAsset::factory()->for($otherWorkspace)->create([
            'disk' => 'scratchpad',
            'path' => $otherWorkspace->id.'/secret.jpg',
            'mime' => 'image/jpeg',
        ]);
        Storage::disk('scratchpad')->put($media->path, 'secret');
        Attachment::factory()->for($otherVideo, 'attachable')->for($media)->create();

        $this->get(route('videos.media', [$otherVideo, $media]))->assertNotFound();
    }
}
