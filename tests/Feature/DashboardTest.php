<?php

namespace Tests\Feature;

use App\Models\Idea;
use App\Models\Post;
use App\Models\ScratchpadEntry;
use App\Models\User;
use App\Models\Video;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function actingAsWorkspaceOwner(): array
    {
        $workspace = Workspace::factory()->create();
        $team = $workspace->team;
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->members()->attach($user->id, ['role' => 'owner']);

        $this->actingAs($user);

        return [$user, $workspace];
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('dashboard.home'))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_users_see_pipeline_summaries(): void
    {
        [, $workspace] = $this->actingAsWorkspaceOwner();

        ScratchpadEntry::factory()->for($workspace)->count(3)->create();
        Idea::factory()->for($workspace)->create(['kind' => 'video', 'status' => 'open']);
        Video::factory()->for($workspace)->create(['status' => 'recorded']);
        Post::factory()->for($workspace)->create(['status' => 'draft']);

        $this->get(route('dashboard.home'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('dashboard/home')
                ->where('scratchpad.untriaged', 3)
                ->where('videos.ideation', 1)
                ->where('videos.recorded', 1)
                ->where('videos.total', 1)
                ->where('posts.draft', 1)
                ->where('upcoming', []));
    }
}
