<?php

namespace App\Actions\Teams;

use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
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
        DB::transaction(function () use ($invitation, $user): void {
            $lockedInvitation = TeamInvitation::query()
                ->whereKey($invitation->id)
                ->lockForUpdate()
                ->first();

            if ($lockedInvitation === null || $lockedInvitation->isAccepted()) {
                throw new RuntimeException('This invitation has already been accepted.');
            }

            if ($lockedInvitation->isExpired()) {
                throw new RuntimeException('This invitation has expired.');
            }

            if (strcasecmp(trim($lockedInvitation->email), trim($user->email)) !== 0) {
                throw new AuthorizationException('This invitation is for a different email address.');
            }

            if (! $user->teams()->whereKey($lockedInvitation->team_id)->exists()) {
                $user->teams()->attach($lockedInvitation->team_id, ['role' => $lockedInvitation->role]);
            }

            $lockedInvitation->forceFill(['accepted_at' => now()])->save();

            if ($user->current_team_id === null) {
                $user->forceFill(['current_team_id' => $lockedInvitation->team_id])->save();
            }
        });
    }
}
