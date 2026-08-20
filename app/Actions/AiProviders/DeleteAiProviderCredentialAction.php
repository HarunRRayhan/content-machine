<?php

namespace App\Actions\AiProviders;

use App\Models\AiProviderCredential;

class DeleteAiProviderCredentialAction
{
    public function handle(AiProviderCredential $credential): void
    {
        $credential->delete();
    }
}
