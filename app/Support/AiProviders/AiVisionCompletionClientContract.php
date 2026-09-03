<?php

namespace App\Support\AiProviders;

use App\Models\AiProviderCredentialModel;

interface AiVisionCompletionClientContract
{
    /**
     * Runs a completion with one image and its accompanying untrusted text.
     */
    public function completeWithImage(
        AiProviderCredentialModel $entry,
        string $systemPrompt,
        string $userContent,
        string $mimeType,
        string $imageContents,
    ): AiCompletionResult;
}
