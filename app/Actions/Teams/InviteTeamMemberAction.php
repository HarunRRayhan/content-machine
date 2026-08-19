<?php

namespace App\Actions\Teams;

use App\Data\Teams\InviteTeamMemberData;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;

/**
 * Creates a pending TeamInvitation row. Doesn't send any email, there's no
 * mailer wired up in this phase, the invite URL is surfaced directly on the
 * team page instead.
 */
class InviteTeamMemberAction
{
    public function handle(Team $team, User $invitedBy, InviteTeamMemberData $data): TeamInvitation
    {
        return $team->invitations()->create([
            'email' => $data->email,
            'role' => $data->role,
            'invited_by_user_id' => $invitedBy->id,
        ]);
    }
}
