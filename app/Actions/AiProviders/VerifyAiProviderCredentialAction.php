<?php

namespace App\Actions\AiProviders;

use App\Models\AiProviderCredential;
use App\Support\AiProviders\AiProviderVerificationResult;
use App\Support\AiProviders\AiProviderVerifierContract;

/**
 * Doubles as "reload models": a provider's available models change over
 * time, so every verification (not just the one at credential creation,
 * see CreateAiProviderCredentialAction) refreshes discovered_models with
 * whatever the provider's list-models endpoint reports right now.
 */
class VerifyAiProviderCredentialAction
{
    public function __construct(
        private readonly AiProviderVerifierContract $verifier,
    ) {}

    public function handle(AiProviderCredential $credential): AiProviderVerificationResult
    {
        $result = $this->verifier->verify($credential);

        if ($result->successful) {
            $credential->update([
                'verified_at' => now(),
                'discovered_models' => $result->models,
            ]);
        }

        return $result;
    }
}
