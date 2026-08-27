<?php

namespace App\Http\Controllers;

use App\Actions\Dashboard\BuildDashboardSummaryAction;
use App\Models\Workspace;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Workspace home: pipeline counts and the next scheduled items.
     */
    public function home(BuildDashboardSummaryAction $buildDashboardSummaryAction): Response
    {
        $workspace = Workspace::current();

        abort_if($workspace === null, 404, 'No current workspace.');

        return Inertia::render(
            'dashboard/home',
            $buildDashboardSummaryAction->handle($workspace)->toArray(),
        );
    }
}
