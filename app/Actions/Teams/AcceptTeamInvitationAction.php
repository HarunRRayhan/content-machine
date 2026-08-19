<?php

namespace App\Actions\Teams;

use App\Models\TeamInvitation;
use App\Models\User;
use RuntimeException;

/**
 * Shared by the invite-accept controller action (an authenticated visitor
 * clicking "Accept & join") and AcceptPendingTeamInvitationOnLogin (an
 * invited email registering or logging in with a pending invite still
 * sitting in their session), so both paths add membership the same way.
 */
class AcceptTeamInvitationAction
{
    /**
     * @throws RuntimeException if the invitation is expired or already accepted
     */
    public function handle(TeamInvitation $invitation, User $user): void
    {
        if ($invitation->isAccepted()) {
            throw new RuntimeException('This invitation has already been accepted.');
        }

        if ($invitation->isExpired()) {
            throw new RuntimeException('This invitation has expired.');
        }

        if (! $user->teams()->whereKey($invitation->team_id)->exists()) {
            $user->teams()->attach($invitation->team_id, ['role' => $invitation->role]);
        }

        $invitation->forceFill(['accepted_at' => now()])->save();

        if ($user->current_team_id === null) {
            $user->forceFill(['current_team_id' => $invitation->team_id])->save();
        }
    }
}
