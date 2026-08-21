<?php

namespace App\Support\AiProviders;

use App\Models\AiProviderCredential;
use App\Models\AiProviderCredentialModel;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;

/**
 * Resolves a workspace's AI fallback chain(s). Two independent chains
 * exist, both scoped to enabled credentials only, in each model's own
 * `priority` order (lowest first, see the ai_provider_credential_models
 * migration): `default` for plain text/chat, `vision` for models that can
 * read images. A default/text task should call textChain(), which tries
 * `default` models first and falls back to `vision` ones (a vision-capable
 * model can still do plain text); a vision task should call
 * chain($workspace, 'vision') directly, since the reverse doesn't hold, a
 * default/vision-less model can't do that job at all.
 *
 * credentialChain() is the separate, older concern: some consumers
 * (transcription) need a specific credential/API key, never a chosen
 * model, so they resolve credentials directly rather than through either
 * purpose chain.
 */
class AiProviderCredentialResolver
{
    /**
     * @return Collection<int, AiProviderCredentialModel>
     */
    public function chain(Workspace $workspace, string $purpose = 'default'): Collection
    {
        return AiProviderCredentialModel::query()
            ->where('purpose', $purpose)
            ->whereHas('credential', fn ($query) => $query
                ->where('workspace_id', $workspace->id)
                ->where('enabled', true))
            ->with('credential')
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, AiProviderCredentialModel>
     */
    public function textChain(Workspace $workspace): Collection
    {
        return $this->chain($workspace, 'default')->concat($this->chain($workspace, 'vision'));
    }

    public function default(Workspace $workspace): ?AiProviderCredentialModel
    {
        return $this->textChain($workspace)->first();
    }

    /**
     * @return Collection<int, AiProviderCredential>
     */
    public function credentialChain(Workspace $workspace): Collection
    {
        return AiProviderCredential::query()
            ->where('workspace_id', $workspace->id)
            ->where('enabled', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }
}
