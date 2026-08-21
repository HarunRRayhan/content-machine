<?php

namespace App\Http\Controllers\AiProviders;

use App\Actions\AiProviders\AddAiProviderCredentialModelsAction;
use App\Actions\AiProviders\RemoveAiProviderCredentialModelAction;
use App\Actions\AiProviders\ReorderAiProviderCredentialModelsAction;
use App\Data\AiProviders\AddAiProviderCredentialModelsData;
use App\Data\AiProviders\ReorderAiProviderCredentialModelsData;
use App\Http\Controllers\Controller;
use App\Http\Requests\AiProviders\AddAiProviderCredentialModelsRequest;
use App\Http\Requests\AiProviders\ReorderAiProviderCredentialModelsRequest;
use App\Models\AiProviderCredential;
use App\Models\AiProviderCredentialModel;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;

/**
 * Owns the left-hand "Models" panel of the AI Models page: which
 * (credential, model, purpose) fallback rungs exist and in what order.
 * AiProviderCredentialsController owns the right-hand providers panel
 * (the credentials/API keys these models are added from).
 */
class AiProviderCredentialModelsController extends Controller
{
    public function store(AddAiProviderCredentialModelsRequest $request, AiProviderCredential $aiProviderCredential, AddAiProviderCredentialModelsAction $addAiProviderCredentialModelsAction): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);

        abort_if($aiProviderCredential->workspace_id !== $workspace->id, 404);

        $addAiProviderCredentialModelsAction->handle($aiProviderCredential, AddAiProviderCredentialModelsData::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Model(s) added.')]);

        return to_route('dashboard.ai-providers.index');
    }

    public function destroy(Request $request, AiProviderCredentialModel $aiProviderCredentialModel, RemoveAiProviderCredentialModelAction $removeAiProviderCredentialModelAction): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);

        // AiProviderCredentialModel itself carries no workspace scope (it
        // has no workspace_id column of its own), so unlike
        // AiProviderCredentialsController's route-bound credentials, this
        // one resolves regardless of workspace. Ownership has to be
        // checked here instead, and deliberately not via
        // $aiProviderCredentialModel->credential: AiProviderCredential's
        // own BelongsToWorkspace scope would silently resolve a genuinely
        // cross-workspace credential to null there, turning this into a
        // crash instead of the 404 it should be.
        $credentialWorkspaceId = AiProviderCredential::withoutGlobalScopes()
            ->where('id', $aiProviderCredentialModel->ai_provider_credential_id)
            ->value('workspace_id');

        abort_if($credentialWorkspaceId !== $workspace->id, 404);

        $removeAiProviderCredentialModelAction->handle($aiProviderCredentialModel);

        return to_route('dashboard.ai-providers.index');
    }

    public function reorder(ReorderAiProviderCredentialModelsRequest $request, ReorderAiProviderCredentialModelsAction $reorderAiProviderCredentialModelsAction): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);

        try {
            $reorderAiProviderCredentialModelsAction->handle($workspace, ReorderAiProviderCredentialModelsData::fromRequest($request));
        } catch (RuntimeException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        return to_route('dashboard.ai-providers.index');
    }

    private function currentWorkspace(Request $request): Workspace
    {
        $workspace = Workspace::current();

        abort_if($workspace === null, 404, 'No current workspace.');

        return $workspace;
    }
}
