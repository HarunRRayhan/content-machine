<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * A minimal dashboard home: just enough to prove tenancy resolved
     * correctly. Real content lives in a later phase.
     */
    public function index(Request $request): Response
    {
        $team = $request->user()?->currentTeam;
        $workspace = Workspace::current();

        return Inertia::render('dashboard', [
            'team' => $team ? ['name' => $team->name, 'slug' => $team->slug] : null,
            'workspace' => $workspace ? ['name' => $workspace->name, 'slug' => $workspace->slug] : null,
        ]);
    }
}
