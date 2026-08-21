<?php

namespace App\Actions\AiProviders;

use App\Data\AiProviders\CreateAiProviderCredentialData;
use App\Models\AiProviderCredential;
use App\Models\Workspace;
use App\Support\AiProviders\AiProviderVerifierContract;

/**
 * Adds a new provider to a workspace, at the end of the providers list: a
 * new key never jumps ahead of an existing one. Reordering that list is a
 * separate, explicit action (ReorderAiProviderCredentialsAction).
 *
 * No model is asked for upfront: the credential saves immediately, then
 * this same call checks the provider's list-models endpoint (the same
 * call VerifyAiProviderCredentialAction makes) and stores whatever it
 * found as discovered_models, for the dashboard to offer models to add
 * to the fallback chain (AddAiProviderCredentialModelsAction). If the
 * check fails or the provider lists nothing, the credential is still
 * saved (label/provider/base_url/api_key are real regardless); a
 * credential with no models added yet simply contributes nothing to
 * AiProviderCredentialResolver's chain, never a guaranteed-to-fail
 * candidate.
 */
class CreateAiProviderCredentialAction
{
    public function __construct(
        private readonly AiProviderVerifierContract $verifier,
    ) {}

    public function handle(Workspace $workspace, CreateAiProviderCredentialData $data): AiProviderCredential
    {
        $nextPriority = 1 + (int) (AiProviderCredential::query()
            ->where('workspace_id', $workspace->id)
            ->max('priority') ?? -1);

        $credential = AiProviderCredential::create([
            'workspace_id' => $workspace->id,
            'label' => $data->label,
            'provider' => $data->provider,
            'base_url' => $data->baseUrl,
            'api_key' => $data->apiKey,
            'priority' => $nextPriority,
            'enabled' => true,
        ]);

        $result = $this->verifier->verify($credential);

        if ($result->successful) {
            $credential->update([
                'verified_at' => now(),
                'discovered_models' => $result->models,
            ]);
        }

        return $credential;
    }
}
