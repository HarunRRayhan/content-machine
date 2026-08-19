<?php

namespace Tests\Unit\Actions\Teams;

use App\Actions\Teams\AcceptTeamInvitationAction;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class AcceptTeamInvitationActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_adds_the_user_to_the_team_and_marks_the_invitation_accepted()
    {
        $team = Team::factory()->create();
        $invitation = TeamInvitation::factory()->for($team)->create(['role' => 'admin']);
        $user = User::factory()->create(['current_team_id' => null]);

        (new AcceptTeamInvitationAction)->handle($invitation, $user);

        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'admin',
        ]);
        $this->assertNotNull($invitation->fresh()->accepted_at);
    }

    public function test_it_switches_current_team_only_when_the_user_had_none(): void
    {
        $existingTeam = Team::factory()->create();
        $invitedTeam = Team::factory()->create();
        $invitation = TeamInvitation::factory()->for($invitedTeam)->create();
        $user = User::factory()->create(['current_team_id' => $existingTeam->id]);

        (new AcceptTeamInvitationAction)->handle($invitation, $user);

        $this->assertSame($existingTeam->id, $user->fresh()->current_team_id);
    }

    public function test_it_sets_current_team_when_the_user_had_none(): void
    {
        $team = Team::factory()->create();
        $invitation = TeamInvitation::factory()->for($team)->create();
        $user = User::factory()->create(['current_team_id' => null]);

        (new AcceptTeamInvitationAction)->handle($invitation, $user);

        $this->assertSame($team->id, $user->fresh()->current_team_id);
    }

    public function test_it_rejects_an_already_accepted_invitation()
    {
        $invitation = TeamInvitation::factory()->accepted()->create();
        $user = User::factory()->create();

        $this->expectException(RuntimeException::class);

        (new AcceptTeamInvitationAction)->handle($invitation, $user);
    }

    public function test_it_rejects_an_expired_invitation()
    {
        $invitation = TeamInvitation::factory()->expired()->create();
        $user = User::factory()->create();

        $this->expectException(RuntimeException::class);

        (new AcceptTeamInvitationAction)->handle($invitation, $user);
    }
}
