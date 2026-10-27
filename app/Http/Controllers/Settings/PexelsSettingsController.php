<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Pexels\SavePexelsSettingsAction;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\Concerns\AuthorizesWorkspaceSettings;
use App\Http\Requests\Settings\UpdatePexelsSettingsRequest;
use App\Support\Pexels\PexelsConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PexelsSettingsController extends Controller
{
    use AuthorizesWorkspaceSettings;

    public function edit(Request $request): Response
    {
        $workspace = $this->currentWorkspace();
        $this->authorizeWorkspaceAdmin($request, $workspace);

        return Inertia::render('workspace-settings/pexels', [
            'apiKeyConfigured' => PexelsConfig::fromWorkspace($workspace)->isConfigured(),
        ]);
    }

    public function update(
        UpdatePexelsSettingsRequest $request,
        SavePexelsSettingsAction $action,
    ): RedirectResponse {
        $action->handle($this->currentWorkspace(), $request->validated('api_key'));

        return to_route('settings.pexels.edit')->with('success', 'Pexels API key saved.');
    }
}
