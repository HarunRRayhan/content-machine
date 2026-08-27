<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\Concerns\AuthorizesWorkspaceSettings;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GeneralSettingsController extends Controller
{
    use AuthorizesWorkspaceSettings;

    public function edit(Request $request): Response
    {
        $workspace = $this->currentWorkspace();
        $this->authorizeWorkspaceAdmin($request, $workspace);

        return Inertia::render('workspace-settings/general');
    }
}
