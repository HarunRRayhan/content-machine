<?php

namespace App\Actions\AiProviders;

use App\Data\AiProviders\CreateAiProviderCredentialData;
use App\Models\AiProviderCredential;
use App\Models\Workspace;

/**
 * Adds a new credential to a workspace's AI fallback chain, at the end of
 * it: the new key never jumps ahead of an existing default. Reordering to
 * make it the default is a separate, explicit action
 * (ReorderAiProviderCredentialsAction).
 */
class CreateAiProviderCredentialAction
{
    public function handle(Workspace $workspace, CreateAiProviderCredentialData $data): AiProviderCredential
    {
        $nextPriority = 1 + (int) (AiProviderCredential::query()
            ->where('workspace_id', $workspace->id)
            ->max('priority') ?? -1);

        return AiProviderCredential::create([
            'workspace_id' => $workspace->id,
            'label' => $data->label,
            'provider' => $data->provider,
            'base_url' => $data->baseUrl,
            'model' => $data->model,
            'api_key' => $data->apiKey,
            'priority' => $nextPriority,
            'enabled' => true,
        ]);
    }
}
