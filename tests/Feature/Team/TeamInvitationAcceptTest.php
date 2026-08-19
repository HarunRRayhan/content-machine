<?php

namespace Tests\Feature\Team;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TeamInvitationAcceptTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_sees_the_team_name_and_is_prompted_to_authenticate()
    {
        $team = Team::factory()->create(['name' => 'Acme']);
        $invitation = TeamInvitation::factory()->for($team)->create();

        $this->get(route('invitations.show', $invitation->token))
            ->assertInertia(fn (Assert $page) => $page
                ->component('invitations/accept')
                ->where('valid', true)
                ->where('teamName', 'Acme')
                ->where('expired', false)
                ->where('accepted', false),
            );
    }

    public function test_an_unknown_token_is_reported_as_invalid()
    {
        $this->get(route('invitations.show', 'not-a-real-token'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('invitations/accept')
                ->where('valid', false),
            );
    }

    public function test_an_authenticated_visitor_can_accept_directly()
    {
        $team = Team::factory()->create();
        $invitation = TeamInvitation::factory()->for($team)->create(['role' => 'admin']);
        $user = User::factory()->create(['current_team_id' => null]);

        $response = $this->actingAs($user)->post(route('invitations.accept', $invitation->token));

        $response->assertRedirect(route('dashboard.home'));
        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'admin',
        ]);
        $this->assertSame($team->id, $user->fresh()->current_team_id);
        $this->assertNotNull($invitation->fresh()->accepted_at);
    }

    public function test_accepting_an_expired_invitation_fails_gracefully()
    {
        $invitation = TeamInvitation::factory()->expired()->create();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('invitations.accept', $invitation->token));

        $response->assertRedirect(route('invitations.show', $invitation->token));
        $this->assertDatabaseMissing('team_user', [
            'team_id' => $invitation->team_id,
            'user_id' => $user->id,
        ]);
    }

    public function test_registering_with_the_invited_email_accepts_it_automatically()
    {
        $team = Team::factory()->create();
        $invitation = TeamInvitation::factory()->for($team)->create([
            'email' => 'invited@example.com',
            'role' => 'admin',
        ]);

        // Visiting the invite page stashes the token in the session, same
        // as a real browser following the emailed/copy-pasted link before
        // registering.
        $this->get(route('invitations.show', $invitation->token));

        $this->post(route('register.store'), [
            'name' => 'Invited Person',
            'email' => 'invited@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $user = User::whereEmail('invited@example.com')->firstOrFail();

        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'admin',
        ]);
        $this->assertNotNull($invitation->fresh()->accepted_at);

        // The user's own personal team (created on registration) stays
        // current; joining via invite doesn't steal it.
        $this->assertNotSame($team->id, $user->fresh()->current_team_id);
    }
}
