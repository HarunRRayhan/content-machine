<?php

namespace App\Support\AiProviders;

use App\Models\AiProviderCredential;

interface AiCompletionClientContract
{
    /**
     * Runs a single, tool-free chat completion: $systemPrompt is this
     * app's own fixed instruction, $userContent is whatever untrusted
     * captured material is being summarized. The two are kept as separate
     * request fields (never concatenated into one string) so a caller
     * never has to sanitize $userContent before passing it in.
     */
    public function complete(AiProviderCredential $credential, string $systemPrompt, string $userContent): AiCompletionResult;
}
