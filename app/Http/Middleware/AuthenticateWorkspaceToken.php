<?php

namespace App\Http\Middleware;

use App\Models\WorkspaceApiToken;
use App\Support\CurrentApiToken;
use App\Support\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a request via a workspace API bearer token ("Authorization:
 * Bearer cm_...") and binds the same request context the dashboard's session
 * flow provides: CurrentWorkspace for BelongsToWorkspace's global scope,
 * Auth::user() so Actions that take an actor get the token's creator, and
 * CurrentApiToken so RecordsHistory attributes writes to the token itself
 * ('token' actor + token_name).
 *
 * When mounted with an ability parameter ('auth.workspace-token:scratchpad:read'),
 * the token must hold that ability or the request is rejected with 403. A
 * revoked token fails lookup entirely (findByPlaintext filters it), reading
 * as unauthenticated rather than forbidden — to a client they are the same
 * instruction: mint a new token.
 *
 * last_used_at is stamped at most once a minute per token, matching
 * Sanctum's own throttle, to keep a chatty poller from writing on every
 * request.
 */
class AuthenticateWorkspaceToken
{
    public function __construct(
        private readonly CurrentApiToken $currentApiToken,
        private readonly CurrentWorkspace $currentWorkspace,
    ) {}

    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $plaintext = $request->bearerToken();

        $token = is_string($plaintext) && str_starts_with($plaintext, 'cm_')
            ? WorkspaceApiToken::findByPlaintext($plaintext)
            : null;

        if ($token === null) {
            abort(401, 'Unauthenticated.');
        }

        foreach ($abilities as $ability) {
            if (! $token->hasAbility($ability)) {
                abort(403, "Token is missing the [{$ability}] ability.");
            }
        }

        $this->currentApiToken->set($token);
        $this->currentWorkspace->set($token->workspace);

        if (($user = $token->createdBy) !== null) {
            Auth::setUser($user);
            $request->setUserResolver(fn () => $user);
        }

        if ($this->shouldStampLastUsed($token)) {
            $token->forceFill(['last_used_at' => now()])->save();
        }

        return $next($request);
    }

    private function shouldStampLastUsed(WorkspaceApiToken $token): bool
    {
        return $token->last_used_at === null
            || $token->last_used_at->lt(now()->subMinute());
    }
}
