<?php

namespace App\Http\Middleware;

use App\Support\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the authenticated user's current team's first (and, in this
 * phase, only) workspace and binds it into the container for the duration
 * of the request, so Workspace::current() returns it.
 *
 * A user with no current team (e.g. a freshly created test user that never
 * went through registration) simply gets no workspace bound rather than a
 * failed request; callers that need one must check for null.
 */
class SetCurrentWorkspace
{
    public function __construct(private readonly CurrentWorkspace $currentWorkspace) {}

    public function handle(Request $request, Closure $next): Response
    {
        $team = $request->user()?->currentTeam;

        if ($team !== null) {
            $this->currentWorkspace->set($team->workspaces()->oldest('id')->first());
        }

        return $next($request);
    }
}
