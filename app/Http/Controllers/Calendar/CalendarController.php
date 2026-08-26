<?php

namespace App\Http\Controllers\Calendar;

use App\Actions\Calendar\ListCalendarEventsAction;
use App\Http\Controllers\Controller;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CalendarController extends Controller
{
    /**
     * Month grid of scheduled and published posts and videos.
     */
    public function index(Request $request, ListCalendarEventsAction $listCalendarEventsAction): Response
    {
        $workspace = $this->currentWorkspace();
        $timezone = $listCalendarEventsAction->timezone($workspace);
        $today = CarbonImmutable::now($timezone);

        $year = $request->integer('year') ?: $today->year;
        $month = $request->integer('month') ?: $today->month;

        if ($year < 2000 || $year > 2100) {
            $year = $today->year;
        }

        if ($month < 1 || $month > 12) {
            $month = $today->month;
        }

        return Inertia::render('calendar/index', [
            'year' => $year,
            'month' => $month,
            'timezone' => $timezone,
            'today' => $today->toDateString(),
            'events' => $listCalendarEventsAction->handle($workspace, $year, $month),
        ]);
    }

    private function currentWorkspace(): Workspace
    {
        $workspace = Workspace::current();

        abort_if($workspace === null, 404, 'No current workspace.');

        return $workspace;
    }
}
