<?php

namespace App\Http\Controllers;

use App\Actions\ApiTokens\CreateWorkspaceApiTokenAction;
use App\Actions\ApiTokens\RevokeWorkspaceApiTokenAction;
use App\Actions\Teams\InviteTeamMemberAction;
use App\Data\ApiTokens\CreateWorkspaceApiTokenData;
use App\Data\Teams\InviteTeamMemberData;
use App\Http\Requests\ApiTokens\StoreWorkspaceApiTokenRequest;
use App\Http\Requests\Team\InviteTeamMemberRequest;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceApiToken;
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
        // Tokens are workspace-scoped, not team-scoped. A team without any
        // workspace yet (freshly created in tests, never in practice) just
        // shows an empty token list rather than failing the whole page.
        $workspace = Workspace::current();

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
            // Live tokens only: revoked ones stay in the DB so history rows
            // keep their meaning, but they're not operational and would only
            // clutter this list.
            'api_tokens' => $workspace === null ? [] : WorkspaceApiToken::query()
                ->where('workspace_id', $workspace->id)
                ->whereNull('revoked_at')
                ->orderBy('name')
                ->get()
                ->map(fn (WorkspaceApiToken $token) => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'abilities' => $token->abilities,
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'created_at' => $token->created_at?->toIso8601String(),
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

    /**
     * Mint a workspace API token. The plaintext is echoed once, in this
     * redirect's toast — only its hash is stored — so the person creating
     * it must capture it in that moment.
     */
    public function storeApiToken(
        StoreWorkspaceApiTokenRequest $request,
        CreateWorkspaceApiTokenAction $createWorkspaceApiTokenAction,
    ): RedirectResponse {
        $user = $this->currentUser($request);
        $workspace = $this->currentWorkspace();

        ['token' => $token, 'plaintext' => $plaintext] = $createWorkspaceApiTokenAction->handle(
            $workspace,
            $user,
            CreateWorkspaceApiTokenData::fromRequest($request),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('API token :name created. Copy it now, it is shown only once: :token', [
                'name' => $token->name,
                'token' => $plaintext,
            ]),
        ]);

        return to_route('dashboard.team.index');
    }

    /**
     * Revoke a workspace API token (soft: the row stays so history rows
     * written under its name keep their meaning).
     */
    public function revokeApiToken(
        Request $request,
        WorkspaceApiToken $apiToken,
        RevokeWorkspaceApiTokenAction $revokeWorkspaceApiTokenAction,
    ): RedirectResponse {
        $workspace = $this->currentWorkspace();

        abort_if($apiToken->workspace_id !== $workspace->id, 404);

        $revokeWorkspaceApiTokenAction->handle($apiToken);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('API token :name revoked.', ['name' => $apiToken->name]),
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

    private function currentWorkspace(): Workspace
    {
        $workspace = Workspace::current();

        abort_if($workspace === null, 404, 'No current workspace.');

        return $workspace;
    }
}
