<?php

namespace App\Http\Controllers\Scratchpad;

use App\Actions\Scratchpad\CaptureTextNoteAction;
use App\Data\Scratchpad\CaptureTextNoteData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Scratchpad\StoreScratchpadTextNoteRequest;
use App\Models\ScratchpadEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ScratchpadController extends Controller
{
    /**
     * List the current workspace's scratchpad entries, newest first, with a
     * quick-capture text note form at the top.
     */
    public function index(Request $request): Response
    {
        $workspace = $this->currentWorkspace($request);

        $entries = ScratchpadEntry::query()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('captured_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (ScratchpadEntry $entry) => $this->presentSummary($entry));

        return Inertia::render('scratchpad/index', [
            'entries' => $entries,
        ]);
    }

    /**
     * Capture a new text note.
     */
    public function store(StoreScratchpadTextNoteRequest $request, CaptureTextNoteAction $captureTextNoteAction): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);
        $user = $this->currentUser($request);

        $captureTextNoteAction->handle($workspace, $user, CaptureTextNoteData::fromRequest($request));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Note captured.'),
        ]);

        return to_route('dashboard.scratchpad.index');
    }

    /**
     * Show a single entry. 404s if it's not in the current workspace so a
     * request can't view another workspace's capture by guessing an id.
     */
    public function show(Request $request, ScratchpadEntry $entry): Response
    {
        $workspace = $this->currentWorkspace($request);

        abort_if($entry->workspace_id !== $workspace->id, 404);

        return Inertia::render('scratchpad/show', [
            'entry' => $this->presentDetail($entry),
        ]);
    }

    private function currentUser(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }

    private function currentWorkspace(Request $request): Workspace
    {
        $workspace = Workspace::current();

        abort_if($workspace === null, 404, 'No current workspace.');

        return $workspace;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentSummary(ScratchpadEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'public_id' => $entry->public_id,
            'kind' => $entry->kind,
            'status' => $entry->status,
            'title' => $entry->title,
            'preview' => $entry->body === null ? null : Str::limit($entry->body, 140),
            'captured_at' => $entry->captured_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDetail(ScratchpadEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'public_id' => $entry->public_id,
            'kind' => $entry->kind,
            'status' => $entry->status,
            'source' => $entry->source,
            'title' => $entry->title,
            'body' => $entry->body,
            'captured_at' => $entry->captured_at->toIso8601String(),
        ];
    }
}
