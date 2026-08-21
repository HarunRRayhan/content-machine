<?php

namespace App\Actions\AiProviders;

use App\Models\AiProviderCredential;

/**
 * Resolves a credential out of its "needs a model" state, whether the
 * value came from the discovered_models picker or was typed by hand.
 * discovered_models is cleared regardless of which: once a model is set,
 * the picker has served its purpose and a later model change goes
 * through the normal edit form, not this one-time flow.
 */
class SetAiProviderCredentialModelAction
{
    public function handle(AiProviderCredential $credential, string $model): AiProviderCredential
    {
        $credential->update([
            'model' => $model,
            'discovered_models' => null,
        ]);

        return $credential;
    }
}
