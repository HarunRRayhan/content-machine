<?php

namespace App\Http\Controllers\ApiTokens;

use App\Actions\ApiTokens\CreateWorkspaceApiTokenAction;
use App\Actions\ApiTokens\RevokeWorkspaceApiTokenAction;
use App\Data\ApiTokens\CreateWorkspaceApiTokenData;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApiTokens\StoreWorkspaceApiTokenRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceApiToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The API access page: everything about this workspace's external-client
 * tokens on one screen. Tokens are workspace-scoped and the workspace is
 * whatever SetCurrentWorkspace resolved for the session — there is no
 * per-team token view, a token never spans teams, so "current team's
 * workspace" is the only sensible scope and needs no picker here.
 */
class WorkspaceApiTokensController extends Controller
{
    public function index(Request $request): Response
    {
        $this->currentUser($request);
        $workspace = $this->currentWorkspace();

        return Inertia::render('dashboard/api-tokens', [
            // Live tokens only: revoked ones stay in the DB so history rows
            // keep their meaning, but they're not operational and would only
            // clutter this list.
            'api_tokens' => WorkspaceApiToken::query()
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
     * Mint a workspace API token. The plaintext is echoed once, in this
     * redirect's toast — only its hash is stored — so the person creating
     * it must capture it in that moment.
     */
    public function store(
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

        // The plaintext goes to the page as a one-request flash prop, where
        // it stays visible in a read-only input until the user clicks
        // "I've saved it" or navigates away. A toast was tried first: it
        // vanished in seconds, taking the only copy of the credential with
        // it.
        $request->session()->flash('new_api_token', $plaintext);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('API token :name created. Copy it below before leaving the page.', [
                'name' => $token->name,
            ]),
        ]);

        return to_route('dashboard.team.api-tokens.index');
    }

    /**
     * Revoke a workspace API token (soft: the row stays so history rows
     * written under its name keep their meaning).
     */
    public function revoke(
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

        return to_route('dashboard.team.api-tokens.index');
    }

    private function currentUser(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }

    private function currentWorkspace(): Workspace
    {
        $workspace = Workspace::current();

        abort_if($workspace === null, 404, 'No current workspace.');

        return $workspace;
    }
}
