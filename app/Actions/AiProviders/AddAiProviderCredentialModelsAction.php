<?php

namespace App\Actions\AiProviders;

use App\Data\AiProviders\AddAiProviderCredentialModelsData;
use App\Models\AiProviderCredential;
use App\Models\AiProviderCredentialModel;

/**
 * Adds one or more of a credential's discovered_models as active fallback
 * rungs (see the ai_provider_credential_models migration), each getting the
 * next priority in that workspace+purpose's chain, after whatever's already
 * there. Unlike the old single-model flow this replaced,
 * discovered_models is never cleared: it stays the candidate pool a
 * credential can keep adding from later, since a workspace can add several
 * models from the same key over time, not just pick one and be done.
 * Already-added (credential, model, purpose) combinations are skipped
 * rather than duplicated.
 */
class AddAiProviderCredentialModelsAction
{
    public function handle(AiProviderCredential $credential, AddAiProviderCredentialModelsData $data): void
    {
        $alreadyAdded = AiProviderCredentialModel::query()
            ->where('ai_provider_credential_id', $credential->id)
            ->where('purpose', $data->purpose)
            ->pluck('model')
            ->all();

        $toAdd = array_values(array_diff(array_unique($data->models), $alreadyAdded));

        if ($toAdd === []) {
            return;
        }

        $nextPriority = 1 + (int) (AiProviderCredentialModel::query()
            ->where('purpose', $data->purpose)
            ->whereHas('credential', fn ($query) => $query->where('workspace_id', $credential->workspace_id))
            ->max('priority') ?? -1);

        foreach ($toAdd as $model) {
            AiProviderCredentialModel::create([
                'ai_provider_credential_id' => $credential->id,
                'model' => $model,
                'purpose' => $data->purpose,
                'priority' => $nextPriority,
            ]);

            $nextPriority++;
        }
    }
}
