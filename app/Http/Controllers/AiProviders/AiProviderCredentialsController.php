<?php

namespace App\Http\Controllers\AiProviders;

use App\Actions\AiProviders\CreateAiProviderCredentialAction;
use App\Actions\AiProviders\DeleteAiProviderCredentialAction;
use App\Actions\AiProviders\ReorderAiProviderCredentialsAction;
use App\Actions\AiProviders\ToggleAiProviderCredentialAction;
use App\Actions\AiProviders\UpdateAiProviderCredentialAction;
use App\Actions\AiProviders\VerifyAiProviderCredentialAction;
use App\Data\AiProviders\CreateAiProviderCredentialData;
use App\Data\AiProviders\ReorderAiProviderCredentialsData;
use App\Data\AiProviders\UpdateAiProviderCredentialData;
use App\Http\Controllers\Controller;
use App\Http\Requests\AiProviders\ReorderAiProviderCredentialsRequest;
use App\Http\Requests\AiProviders\StoreAiProviderCredentialRequest;
use App\Http\Requests\AiProviders\UpdateAiProviderCredentialRequest;
use App\Models\AiProviderCredential;
use App\Models\AiProviderCredentialModel;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class AiProviderCredentialsController extends Controller
{
    /**
     * Renders both panels of the AI Models page: providers (this
     * workspace's API keys, priority ascending) on the right, and the two
     * model fallback chains (default, vision; each priority ascending,
     * the order AiProviderCredentialResolver actually consumes) on the
     * left. See AiProviderCredentialModelsController for the actions that
     * change the left side.
     */
    public function index(Request $request): Response
    {
        $workspace = $this->currentWorkspace($request);

        $credentials = AiProviderCredential::query()
            ->where('workspace_id', $workspace->id)
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->map(fn (AiProviderCredential $credential) => $this->presentCredential($credential));

        $models = AiProviderCredentialModel::query()
            ->whereHas('credential', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->with('credential')
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        return Inertia::render('ai-providers/index', [
            'credentials' => $credentials,
            'models' => [
                'default' => $models->where('purpose', 'default')->values()->map($this->presentModelEntry(...)),
                'vision' => $models->where('purpose', 'vision')->values()->map($this->presentModelEntry(...)),
            ],
        ]);
    }

    public function store(StoreAiProviderCredentialRequest $request, CreateAiProviderCredentialAction $createAiProviderCredentialAction): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);

        $createAiProviderCredentialAction->handle($workspace, CreateAiProviderCredentialData::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Provider added.')]);

        return to_route('dashboard.ai-providers.index');
    }

    public function update(UpdateAiProviderCredentialRequest $request, AiProviderCredential $aiProviderCredential, UpdateAiProviderCredentialAction $updateAiProviderCredentialAction): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);

        abort_if($aiProviderCredential->workspace_id !== $workspace->id, 404);

        $updateAiProviderCredentialAction->handle($aiProviderCredential, UpdateAiProviderCredentialData::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Provider updated.')]);

        return to_route('dashboard.ai-providers.index');
    }

    public function destroy(Request $request, AiProviderCredential $aiProviderCredential, DeleteAiProviderCredentialAction $deleteAiProviderCredentialAction): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);

        abort_if($aiProviderCredential->workspace_id !== $workspace->id, 404);

        $deleteAiProviderCredentialAction->handle($aiProviderCredential);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Provider removed.')]);

        return to_route('dashboard.ai-providers.index');
    }

    public function toggle(Request $request, AiProviderCredential $aiProviderCredential, ToggleAiProviderCredentialAction $toggleAiProviderCredentialAction): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);

        abort_if($aiProviderCredential->workspace_id !== $workspace->id, 404);

        $toggleAiProviderCredentialAction->handle($aiProviderCredential);

        return to_route('dashboard.ai-providers.index');
    }

    public function reorder(ReorderAiProviderCredentialsRequest $request, ReorderAiProviderCredentialsAction $reorderAiProviderCredentialsAction): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);

        try {
            $reorderAiProviderCredentialsAction->handle($workspace, ReorderAiProviderCredentialsData::fromRequest($request));
        } catch (RuntimeException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return to_route('dashboard.ai-providers.index');
        }

        return to_route('dashboard.ai-providers.index');
    }

    public function verify(Request $request, AiProviderCredential $aiProviderCredential, VerifyAiProviderCredentialAction $verifyAiProviderCredentialAction): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);

        abort_if($aiProviderCredential->workspace_id !== $workspace->id, 404);

        $result = $verifyAiProviderCredentialAction->handle($aiProviderCredential);

        Inertia::flash('toast', [
            'type' => $result->successful ? 'success' : 'error',
            'message' => $result->successful ? __('Key verified.') : $result->error,
        ]);

        return to_route('dashboard.ai-providers.index');
    }

    private function currentWorkspace(Request $request): Workspace
    {
        $workspace = Workspace::current();

        abort_if($workspace === null, 404, 'No current workspace.');

        return $workspace;
    }

    /**
     * The key itself is never included: it's write-only from the client's
     * side once saved (see UpdateAiProviderCredentialRequest's docblock).
     *
     * @return array<string, mixed>
     */
    private function presentCredential(AiProviderCredential $credential): array
    {
        return [
            'id' => $credential->id,
            'label' => $credential->label,
            'provider' => $credential->provider,
            'base_url' => $credential->base_url,
            'discovered_models' => $credential->discovered_models,
            'priority' => $credential->priority,
            'enabled' => $credential->enabled,
            'verified_at' => $credential->verified_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentModelEntry(AiProviderCredentialModel $entry): array
    {
        return [
            'id' => $entry->id,
            'model' => $entry->model,
            'purpose' => $entry->purpose,
            'priority' => $entry->priority,
            'credential' => [
                'id' => $entry->credential->id,
                'label' => $entry->credential->label,
                'provider' => $entry->credential->provider,
            ],
        ];
    }
}
