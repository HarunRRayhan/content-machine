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
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class AiProviderCredentialsController extends Controller
{
    /**
     * List the current workspace's AI credentials in fallback order
     * (priority ascending), the same order AiProviderCredentialResolver
     * will use once an agent consumes it.
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

        return Inertia::render('ai-providers/index', [
            'credentials' => $credentials,
        ]);
    }

    public function store(StoreAiProviderCredentialRequest $request, CreateAiProviderCredentialAction $createAiProviderCredentialAction): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);

        $createAiProviderCredentialAction->handle($workspace, CreateAiProviderCredentialData::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Credential added.')]);

        return to_route('dashboard.ai-providers.index');
    }

    public function update(UpdateAiProviderCredentialRequest $request, AiProviderCredential $aiProviderCredential, UpdateAiProviderCredentialAction $updateAiProviderCredentialAction): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);

        abort_if($aiProviderCredential->workspace_id !== $workspace->id, 404);

        $updateAiProviderCredentialAction->handle($aiProviderCredential, UpdateAiProviderCredentialData::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Credential updated.')]);

        return to_route('dashboard.ai-providers.index');
    }

    public function destroy(Request $request, AiProviderCredential $aiProviderCredential, DeleteAiProviderCredentialAction $deleteAiProviderCredentialAction): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);

        abort_if($aiProviderCredential->workspace_id !== $workspace->id, 404);

        $deleteAiProviderCredentialAction->handle($aiProviderCredential);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Credential removed.')]);

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
            'model' => $credential->model,
            'priority' => $credential->priority,
            'enabled' => $credential->enabled,
            'verified_at' => $credential->verified_at?->toIso8601String(),
        ];
    }
}
