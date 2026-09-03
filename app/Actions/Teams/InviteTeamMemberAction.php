<?php

namespace App\Actions\Teams;

use App\Data\Teams\InviteTeamMemberData;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Creates a pending TeamInvitation row. Doesn't send any email, there's no
 * mailer wired up in this phase, the invite URL is surfaced directly on the
 * team page instead.
 */
class InviteTeamMemberAction
{
    public function handle(Team $team, User $invitedBy, InviteTeamMemberData $data): TeamInvitation
    {
        return DB::transaction(function () use ($team, $invitedBy, $data): TeamInvitation {
            $role = DB::table('team_user')
                ->where('team_id', $team->id)
                ->where('user_id', $invitedBy->id)
                ->value('role');

            if (! is_string($role) || ! in_array($role, ['owner', 'admin'], true)) {
                throw new AuthorizationException('Only team owners and admins can send invitations.');
            }

            if ($data->role === 'owner' && $role !== 'owner') {
                throw new AuthorizationException('Only the team owner can invite another owner.');
            }

            return $team->invitations()->create([
                'email' => $data->email,
                'role' => $data->role,
                'invited_by_user_id' => $invitedBy->id,
            ]);
        });
    }
}
