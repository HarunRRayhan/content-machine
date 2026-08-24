<?php

namespace App\Http\Controllers;

use App\Actions\Teams\InviteTeamMemberAction;
use App\Data\Teams\InviteTeamMemberData;
use App\Http\Requests\Team\InviteTeamMemberRequest;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    /**
     * Show the current team's members and pending invitations, and let the
     * user send a new one.
     */
    public function index(Request $request): Response
    {
        $team = $this->currentTeam($this->currentUser($request));

        return Inertia::render('dashboard/team', [
            'team' => [
                'name' => $team->name,
                'slug' => $team->slug,
            ],
            'members' => $team->members()
                ->orderBy('name')
                ->get()
                ->map(fn ($member) => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'role' => $member->pivot->role,
                ])
                ->values(),
            'invitations' => $team->invitations()
                ->whereNull('accepted_at')
                ->latest()
                ->get()
                ->map(fn (TeamInvitation $invitation) => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'role' => $invitation->role,
                    'expired' => $invitation->isExpired(),
                    'url' => route('invitations.show', $invitation->token),
                ])
                ->values(),
        ]);
    }

    /**
     * Send a new invitation. There's no mailer wired up in this phase, so
     * the invite URL is simply returned on the team page for whoever's
     * testing to copy and paste manually.
     */
    public function storeInvitation(InviteTeamMemberRequest $request, InviteTeamMemberAction $inviteTeamMemberAction): RedirectResponse
    {
        $user = $this->currentUser($request);
        $team = $this->currentTeam($user);

        $invitation = $inviteTeamMemberAction->handle($team, $user, InviteTeamMemberData::fromRequest($request));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Invitation created for :email.', ['email' => $invitation->email]),
        ]);

        return to_route('dashboard.team.index');
    }

    private function currentUser(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }

    private function currentTeam(User $user): Team
    {
        $team = $user->currentTeam;

        abort_if($team === null, 404, 'No current team.');

        return $team;
    }
}
