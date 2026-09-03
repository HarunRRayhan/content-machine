<?php

namespace App\Http\Controllers;

use App\Actions\Teams\AcceptTeamInvitationAction;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class TeamInvitationController extends Controller
{
    /**
     * Public invite-landing page. A guest sees the team name and is
     * prompted to register or log in; an authenticated visitor sees an
     * "accept" button. Either way we stash the token in the session so
     * AcceptPendingTeamInvitationOnLogin can complete the join
     * automatically if they register/log in with the invited address.
     */
    public function show(Request $request, string $token): Response
    {
        $invitation = TeamInvitation::where('token', $token)->with('team')->first();

        if ($invitation !== null) {
            $request->session()->put('pending_invitation_token', $token);
        }

        return Inertia::render('invitations/accept', [
            'token' => $token,
            'valid' => $invitation !== null,
            'teamName' => $invitation?->team->name,
            'invitedEmail' => $invitation?->email,
            'expired' => $invitation?->isExpired() ?? false,
            'accepted' => $invitation?->isAccepted() ?? false,
        ]);
    }

    /**
     * An already-authenticated visitor accepting the invite directly from
     * the invite page. The action verifies that the account email matches
     * the invited address before adding membership.
     */
    public function accept(Request $request, string $token, AcceptTeamInvitationAction $acceptTeamInvitationAction): RedirectResponse
    {
        $invitation = TeamInvitation::where('token', $token)->firstOrFail();

        $user = $request->user();
        abort_if(! $user instanceof User, 403);

        try {
            $acceptTeamInvitationAction->handle($invitation, $user);
        } catch (RuntimeException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return to_route('invitations.show', $token);
        }

        $request->session()->forget('pending_invitation_token');

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('You joined :team.', ['team' => $invitation->team->name]),
        ]);

        return to_route('dashboard.home');
    }
}
