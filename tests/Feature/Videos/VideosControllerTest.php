<?php

namespace Tests\Feature\Videos;

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

        $mine = Video::factory()->for($workspace)->create(['title' => 'Mine']);

        $otherWorkspace = Workspace::factory()->create();
        Video::factory()->for($otherWorkspace)->create(['title' => 'Not mine']);

        $this->get(route('dashboard.videos.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('videos/index')
                ->has('videos.data', 1)
                ->where('videos.data.0.id', $mine->id)
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
