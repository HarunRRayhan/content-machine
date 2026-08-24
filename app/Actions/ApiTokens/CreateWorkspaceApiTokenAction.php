<?php

namespace App\Actions\ApiTokens;

use App\Data\ApiTokens\CreateWorkspaceApiTokenData;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceApiToken;
use Illuminate\Support\Str;

/**
 * Mints a workspace API token. Returns the plaintext exactly once, to the
 * caller, at creation time: only its SHA-256 hash is stored, so a token
 * whose plaintext wasn't captured in that moment can never be recovered,
 * only replaced. This is deliberate — the plaintext is the credential an
 * external client (personal-content) holds.
 */
class CreateWorkspaceApiTokenAction
{
    /**
     * @return array{token: WorkspaceApiToken, plaintext: string}
     */
    public function handle(Workspace $workspace, ?User $creator, CreateWorkspaceApiTokenData $data): array
    {
        $plaintext = 'cm_'.Str::random(40);

        $token = WorkspaceApiToken::create([
            'workspace_id' => $workspace->id,
            'created_by_user_id' => $creator?->id,
            'name' => $data->name,
            'token_hash' => WorkspaceApiToken::hash($plaintext),
            'abilities' => $data->abilities,
        ]);

        return ['token' => $token, 'plaintext' => $plaintext];
    }
}
