<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard.home'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard.home'));
        $response->assertOk();
    }

    public function test_a_user_with_no_team_sees_a_dashboard_with_no_team_data()
    {
        $user = User::factory()->create(['current_team_id' => null]);

        $this->actingAs($user)
            ->get(route('dashboard.home'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('team', null)
                ->where('workspace', null),
            );
    }

    public function test_it_shows_the_current_team_and_workspace_name()
    {
        $workspace = Workspace::factory()->create(['name' => 'Default', 'slug' => 'default']);
        $team = $workspace->team;
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->members()->attach($user->id, ['role' => 'owner']);

        $this->actingAs($user)
            ->get(route('dashboard.home'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('team.name', $team->name)
                ->where('workspace.name', 'Default'),
            );
    }
}
