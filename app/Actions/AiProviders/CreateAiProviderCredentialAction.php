<?php

namespace App\Actions\AiProviders;

use App\Data\AiProviders\CreateAiProviderCredentialData;
use App\Models\AiProviderCredential;
use App\Models\Workspace;
use App\Support\AiProviders\AiProviderVerifierContract;

/**
 * Adds a new credential to a workspace's AI fallback chain, at the end of
 * it: the new key never jumps ahead of an existing default. Reordering to
 * make it the default is a separate, explicit action
 * (ReorderAiProviderCredentialsAction).
 *
 * No model is asked for upfront: the credential saves immediately with
 * model = null, then this same call checks the provider's list-models
 * endpoint (the same call VerifyAiProviderCredentialAction makes) and
 * stores whatever it found as discovered_models, for the dashboard to
 * offer as a picker. If the check fails or the provider lists nothing,
 * the credential is still saved (label/provider/base_url/api_key are
 * real regardless); the dashboard falls back to asking for a model by
 * hand. AiProviderCredentialResolver excludes a model === null credential
 * from the fallback chain, so an unresolved one is inert, never a
 * guaranteed-to-fail candidate.
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
            'model' => null,
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
