<?php

namespace App\Actions\AiProviders;

use App\Data\AiProviders\UpdateAiProviderCredentialData;
use App\Models\AiProviderCredential;

class UpdateAiProviderCredentialAction
{
    public function handle(AiProviderCredential $credential, UpdateAiProviderCredentialData $data): AiProviderCredential
    {
        $credential->forceFill([
            'label' => $data->label,
            'base_url' => $data->baseUrl,
        ]);

        if ($data->apiKey !== null) {
            // A new key invalidates any earlier verification of the old one.
            $credential->api_key = $data->apiKey;
            $credential->verified_at = null;
        }

        $credential->save();

        return $credential;
    }
}
