<?php

namespace Tests\Feature\Team;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TeamManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_view_the_team_page()
    {
        $this->get(route('dashboard.team.index'))->assertRedirect(route('login'));
    }

    public function test_a_member_sees_the_team_roster_and_pending_invitations()
    {
        $team = Team::factory()->create(['name' => 'Acme']);
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->members()->attach($user->id, ['role' => 'owner']);

        $invitation = TeamInvitation::factory()->for($team)->create(['email' => 'pending@example.com']);

        $this->actingAs($user)
            ->get(route('dashboard.team.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard/team')
                ->where('team.name', 'Acme')
                ->has('members', 1)
                ->has('invitations', 1)
                ->where('invitations.0.email', $invitation->email)
                ->where('invitations.0.url', route('invitations.show', $invitation->token)),
            );
    }

    public function test_a_member_can_send_an_invitation()
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->members()->attach($user->id, ['role' => 'owner']);

        $response = $this->actingAs($user)->post(route('dashboard.team.invitations.store'), [
            'email' => 'new-member@example.com',
            'role' => 'member',
        ]);

        $response->assertRedirect(route('dashboard.team.index'));

        $this->assertDatabaseHas('team_invitations', [
            'team_id' => $team->id,
            'email' => 'new-member@example.com',
            'role' => 'member',
            'invited_by_user_id' => $user->id,
        ]);
    }

    public function test_the_invitation_requires_a_valid_email_and_role()
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->members()->attach($user->id, ['role' => 'owner']);

        $response = $this->actingAs($user)->post(route('dashboard.team.invitations.store'), [
            'email' => 'not-an-email',
            'role' => 'dictator',
        ]);

        $response->assertSessionHasErrors(['email', 'role']);
    }
}
