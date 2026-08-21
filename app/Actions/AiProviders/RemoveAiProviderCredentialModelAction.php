<?php

namespace App\Actions\AiProviders;

use App\Models\AiProviderCredentialModel;

/**
 * Drops one fallback rung. Deliberately just a delete, not a soft
 * disable: an added model is either an active fallback candidate or it
 * isn't, there's no third state to preserve here (contrast with
 * ToggleAiProviderCredentialAction, where a disabled credential keeps its
 * configuration around).
 */
class RemoveAiProviderCredentialModelAction
{
    public function handle(AiProviderCredentialModel $entry): void
    {
        $entry->delete();
    }
}
