<?php

namespace Tests\Feature\Videos;

use App\Models\Idea;
use App\Models\User;
use App\Models\Video;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->get(route('dashboard.videos.index'))->assertRedirect(route('login'));
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

        $this->get(route('dashboard.videos.index'))
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

        $this->get(route('dashboard.videos.index'))
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

        $this->get(route('dashboard.videos.index', ['status' => 'ideation']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('videos/index')
                ->has('items.data', 4)
                ->where('items.data.0.title', 'Hot idea')
                ->where('items.data.1.title', 'Mid idea')
                ->where('items.data.2.title', 'Cold idea')
                ->where('items.data.3.title', 'Unscored idea')
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

        $this->get(route('dashboard.videos.index', ['status' => 'ideation']))
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

        $this->get(route('dashboard.videos.index'))
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

        $this->get(route('dashboard.videos.index', ['status' => 'ready']))
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

        $this->get(route('dashboard.videos.show', $video))
            ->assertInertia(fn (Assert $page) => $page
                ->component('videos/show')
                ->where('video.id', $video->id)
                ->where('video.title', 'Hello video')
            );
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

        $this->get(route('dashboard.videos.index', ['status' => 'ready']))
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

        $this->get(route('dashboard.videos.show', $video))
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

        $this->get(route('dashboard.videos.show', $video))->assertNotFound();
    }

    public function test_update_edits_the_video()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $video = Video::factory()->for($workspace)->create(['title' => 'Old']);

        $response = $this->patch(route('dashboard.videos.update', $video), [
            'title' => 'New',
            'body' => 'Updated body.',
        ]);

        $response->assertRedirect(route('dashboard.videos.show', $video));

        $this->assertDatabaseHas('videos', [
            'id' => $video->id,
            'title' => 'New',
            'body' => 'Updated body.',
        ]);
    }

    public function test_update_404s_for_a_video_in_a_different_workspace()
    {
        $this->actingAsWorkspaceMember();

        $otherWorkspace = Workspace::factory()->create();
        $video = Video::factory()->for($otherWorkspace)->create();

        $this->patch(route('dashboard.videos.update', $video), ['title' => 'Nope'])->assertNotFound();
    }
}
