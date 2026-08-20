<?php

namespace App\Http\Controllers\Ideas;

use App\Actions\Ideas\DropIdeaAction;
use App\Actions\Ideas\PromoteIdeaAction;
use App\Actions\Ideas\UpdateIdeaAction;
use App\Data\Ideas\DropIdeaData;
use App\Data\Ideas\UpdateIdeaData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ideas\DropIdeaRequest;
use App\Http\Requests\Ideas\UpdateIdeaRequest;
use App\Models\Idea;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class IdeasController extends Controller
{
    /**
     * List the current workspace's ideas, optionally filtered by kind
     * (post/video) and status (open/promoted/dropped), newest first. Score
     * isn't set on every idea (it's nullable until triage assigns one), so
     * sorting by score first would scatter unscored ideas unpredictably;
     * newest-first is stable and matches the Scratch Pad list's own
     * convention.
     */
    public function index(Request $request): Response
    {
        $workspace = $this->currentWorkspace($request);

        $kind = $request->string('kind')->toString() ?: null;
        $status = $request->string('status')->toString() ?: null;

        $ideas = Idea::query()
            ->where('workspace_id', $workspace->id)
            ->when($kind !== null, fn ($query) => $query->where('kind', $kind))
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->latest()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Idea $idea) => $this->presentSummary($idea));

        return Inertia::render('ideas/index', [
            'ideas' => $ideas,
            'filters' => [
                'kind' => $kind,
                'status' => $status,
            ],
        ]);
    }

    /**
     * Show a single idea. 404s if it's not in the current workspace so a
     * request can't view another workspace's idea by guessing an id.
     */
    public function show(Request $request, Idea $idea): Response
    {
        $workspace = $this->currentWorkspace($request);

        abort_if($idea->workspace_id !== $workspace->id, 404);

        return Inertia::render('ideas/show', [
            'idea' => $this->presentDetail($idea),
        ]);
    }

    public function update(UpdateIdeaRequest $request, Idea $idea, UpdateIdeaAction $updateIdeaAction): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);

        abort_if($idea->workspace_id !== $workspace->id, 404);

        $updateIdeaAction->handle($idea, UpdateIdeaData::fromRequest($request));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Idea updated.'),
        ]);

        return to_route('dashboard.ideas.show', $idea);
    }

    public function drop(DropIdeaRequest $request, Idea $idea, DropIdeaAction $dropIdeaAction): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);

        abort_if($idea->workspace_id !== $workspace->id, 404);

        try {
            $dropIdeaAction->handle($idea, DropIdeaData::fromRequest($request));
        } catch (RuntimeException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return to_route('dashboard.ideas.show', $idea);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Idea dropped.'),
        ]);

        return to_route('dashboard.ideas.show', $idea);
    }

    /**
     * Promote an open idea into a draft post/video shell. No user input:
     * everything PromoteIdeaAction needs comes from the idea itself.
     */
    public function promote(Request $request, Idea $idea, PromoteIdeaAction $promoteIdeaAction): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);

        abort_if($idea->workspace_id !== $workspace->id, 404);

        try {
            $entity = $promoteIdeaAction->handle($idea);
        } catch (RuntimeException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return to_route('dashboard.ideas.show', $idea);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Promoted to :humanId.', ['humanId' => $entity->human_id]),
        ]);

        return to_route('dashboard.ideas.show', $idea);
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
    private function presentSummary(Idea $idea): array
    {
        return [
            'id' => $idea->id,
            'human_id' => $idea->human_id,
            'kind' => $idea->kind,
            'title' => $idea->title,
            'score' => $idea->score,
            'trend' => $idea->trend,
            'status' => $idea->status,
            'created_at' => $idea->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDetail(Idea $idea): array
    {
        return [
            'id' => $idea->id,
            'human_id' => $idea->human_id,
            'kind' => $idea->kind,
            'title' => $idea->title,
            'slug' => $idea->slug,
            'score' => $idea->score,
            'trend' => $idea->trend,
            'rationale' => $idea->rationale,
            'body' => $idea->body,
            'status' => $idea->status,
            'drop_reason' => $idea->drop_reason,
            'created_at' => $idea->created_at?->toIso8601String(),
            'promoted_to' => $this->presentPromotedEntity($idea),
        ];
    }

    /**
     * The draft post/video this idea was promoted into, presented just
     * enough to show it happened; there's no post/video detail page yet
     * to link to. Null for an unpromoted idea.
     *
     * @return array<string, mixed>|null
     */
    private function presentPromotedEntity(Idea $idea): ?array
    {
        $entity = $idea->kind === 'video' ? $idea->video : $idea->post;

        if ($entity === null) {
            return null;
        }

        return [
            'human_id' => $entity->human_id,
            'title' => $entity->title,
            'status' => $entity->status,
        ];
    }
}
