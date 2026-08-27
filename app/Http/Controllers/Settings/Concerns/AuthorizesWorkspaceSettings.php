<?php

namespace App\Http\Controllers\Settings\Concerns;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;

trait AuthorizesWorkspaceSettings
{
    private function currentWorkspace(): Workspace
    {
        $workspace = Workspace::current();

        abort_if($workspace === null, 404, 'No current workspace.');

        return $workspace;
    }

    private function authorizeWorkspaceAdmin(Request $request, Workspace $workspace): void
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        $member = $workspace->team->members()->whereKey($user->id)->first();

        abort_unless(in_array($member?->pivot->role, ['owner', 'admin'], true), 403);
    }
}
