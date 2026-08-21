<?php

namespace App\Support\AiProviders;

use App\Models\AiProviderCredentialModel;

interface AiCompletionClientContract
{
    /**
     * Runs a single, tool-free chat completion against one specific
     * fallback-chain entry (a credential plus the model it should use):
     * $systemPrompt is this app's own fixed instruction, $userContent is
     * whatever untrusted captured material is being summarized. The two
     * are kept as separate request fields (never concatenated into one
     * string) so a caller never has to sanitize $userContent before
     * passing it in.
     */
    public function complete(AiProviderCredentialModel $entry, string $systemPrompt, string $userContent): AiCompletionResult;
}
