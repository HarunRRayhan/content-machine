<?php

namespace App\Actions\AiProviders;

use App\Models\AiProviderCredential;

class ToggleAiProviderCredentialAction
{
    public function handle(AiProviderCredential $credential): AiProviderCredential
    {
        $credential->update(['enabled' => ! $credential->enabled]);

        return $credential;
    }
}
