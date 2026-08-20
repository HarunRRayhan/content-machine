<?php

namespace App\Actions\AiProviders;

use App\Models\AiProviderCredential;
use App\Support\AiProviders\AiProviderVerificationResult;
use App\Support\AiProviders\AiProviderVerifierContract;

class VerifyAiProviderCredentialAction
{
    public function __construct(
        private readonly AiProviderVerifierContract $verifier,
    ) {}

    public function handle(AiProviderCredential $credential): AiProviderVerificationResult
    {
        $result = $this->verifier->verify($credential);

        if ($result->successful) {
            $credential->update(['verified_at' => now()]);
        }

        return $result;
    }
}
