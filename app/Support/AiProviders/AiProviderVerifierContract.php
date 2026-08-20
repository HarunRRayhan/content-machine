<?php

namespace App\Support\AiProviders;

use App\Models\AiProviderCredential;

interface AiProviderVerifierContract
{
    public function verify(AiProviderCredential $credential): AiProviderVerificationResult;
}
