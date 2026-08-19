<?php

namespace Tests\Unit\Actions\Teams;

use App\Actions\Teams\InviteTeamMemberAction;
use App\Data\Teams\InviteTeamMemberData;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InviteTeamMemberActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_pending_invitation()
    {
        $team = Team::factory()->create();
        $inviter = User::factory()->create();
        $data = new InviteTeamMemberData(email: 'new-member@example.com', role: 'member');

        $invitation = (new InviteTeamMemberAction)->handle($team, $inviter, $data);

        $this->assertSame($team->id, $invitation->team_id);
        $this->assertSame('new-member@example.com', $invitation->email);
        $this->assertSame('member', $invitation->role);
        $this->assertSame($inviter->id, $invitation->invited_by_user_id);
        $this->assertNotEmpty($invitation->token);
        $this->assertNull($invitation->accepted_at);
        $this->assertTrue($invitation->expires_at->isFuture());
    }
}
