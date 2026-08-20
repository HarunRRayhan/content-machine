<?php

namespace App\Support\AiProviders;

use App\Models\AiProviderCredential;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;

/**
 * Resolves a workspace's AI fallback chain: enabled credentials in
 * priority order (lowest first), so a caller can try the first one and
 * fall through the rest on a provider error. Nothing here makes the
 * actual API call, that's for whichever future agent (TriageAgent,
 * CaptureSummarizer) consumes the chain, each provider needs a different
 * request shape and this resolver only owns ordering.
 */
class AiProviderCredentialResolver
{
    /**
     * @return Collection<int, AiProviderCredential>
     */
    public function chain(Workspace $workspace): Collection
    {
        return AiProviderCredential::query()
            ->where('workspace_id', $workspace->id)
            ->where('enabled', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    public function default(Workspace $workspace): ?AiProviderCredential
    {
        return $this->chain($workspace)->first();
    }
}
