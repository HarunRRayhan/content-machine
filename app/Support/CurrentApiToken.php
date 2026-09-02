<?php

namespace App\Support;

use App\Models\WorkspaceApiToken;

/**
 * Holds the workspace API token resolved for the current request, if the
 * request authenticated with one. Bound as a singleton and populated by
 * AuthenticateWorkspaceToken middleware; empty for every dashboard/web
 * request, which is why RecordsHistory::resolveActor() checks it first but
 * falls through to the session user.
 */
class CurrentApiToken
{
    private ?WorkspaceApiToken $token = null;

    public function set(?WorkspaceApiToken $token): void
    {
        $this->token = $token;
    }

    public function get(): ?WorkspaceApiToken
    {
        return $this->token;
    }

    public function can(string $ability): bool
    {
        // Resources are also used by MCP and internal callers, where there is
        // no bearer token and the complete representation is intentional.
        return $this->token === null || $this->token->hasAbility($ability);
    }
}
