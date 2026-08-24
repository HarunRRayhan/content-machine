<?php

namespace App\Actions\ApiTokens;

use App\Models\WorkspaceApiToken;

/**
 * Revokes a workspace API token by stamping revoked_at rather than deleting
 * the row: status_transitions/content_versions rows written under this
 * token's name stay explainable. Idempotent — revoking an already-revoked
 * token succeeds quietly.
 */
class RevokeWorkspaceApiTokenAction
{
    public function handle(WorkspaceApiToken $token): void
    {
        if ($token->isRevoked()) {
            return;
        }

        $token->forceFill(['revoked_at' => now()])->save();
    }
}
