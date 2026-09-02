<?php

namespace App\Listeners;

use App\Actions\Teams\AcceptTeamInvitationAction;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Auth\Events\Login;

/**
 * When a visitor opens an invite link (TeamInvitationController::show)
 * we stash the token in their session. If they then register or log in
 * with the invited email address, this fires on the resulting Login event
 * (Fortify's register flow logs the new user in, which dispatches Login
 * too, so listening here covers both) and completes the join without
 * making them click a second "accept" button.
 *
 * An email mismatch is left alone here; only the account using the
 * invited address can complete the invitation.
 */
class AcceptPendingTeamInvitationOnLogin
{
    public function __construct(private readonly AcceptTeamInvitationAction $acceptTeamInvitationAction) {}

    public function handle(Login $event): void
    {
        $token = session('pending_invitation_token');

        if (! is_string($token) || ! $event->user instanceof User) {
            return;
        }

        $invitation = TeamInvitation::where('token', $token)->first();

        if (! $invitation || $invitation->isAccepted() || $invitation->isExpired()) {
            session()->forget('pending_invitation_token');

            return;
        }

        if (strcasecmp($invitation->email, $event->user->email) !== 0) {
            return;
        }

        $this->acceptTeamInvitationAction->handle($invitation, $event->user);

        session()->forget('pending_invitation_token');
    }
}
