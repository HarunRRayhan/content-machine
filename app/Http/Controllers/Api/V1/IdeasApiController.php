<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Ideas\UpdateIdeaAction;
use App\Data\Ideas\UpdateIdeaData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ideas\UpdateIdeaRequest;
use App\Http\Resources\V1\IdeaResource;
use App\Models\Idea;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read/edit access to a workspace's ideas for API clients. human_id
 * (PI-7 / VI-3) is the stable address, matching how personal-content talks
 * about ideas. Editing goes through the same UpdateIdeaAction as the
 * dashboard; drop/promote stay dashboard-only this slice.
 */
class IdeasApiController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->currentWorkspace();

        $kind = $request->string('kind')->toString() ?: null;
        $status = $request->string('status')->toString() ?: null;

        $ideas = Idea::query()
            ->when($kind !== null, fn ($query) => $query->where('kind', $kind))
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->cursorPaginate(50)
            ->withQueryString();

        return IdeaResource::collection($ideas);
    }

    public function show(string $humanId): IdeaResource
    {
        return new IdeaResource($this->resolveIdea($humanId));
    }

    public function update(UpdateIdeaRequest $request, string $humanId, UpdateIdeaAction $action): IdeaResource
    {
        $idea = $this->resolveIdea($humanId);

        $action->handle($idea, UpdateIdeaData::fromRequest($request));

        return new IdeaResource($idea->fresh());
    }

    private function resolveIdea(string $humanId): Idea
    {
        $workspace = $this->currentWorkspace();

        $idea = Idea::query()->where('human_id', $humanId)->firstOrFail();

        abort_if($idea->workspace_id !== $workspace->id, 404);

        return $idea;
    }

    private function currentWorkspace(): Workspace
    {
        $workspace = Workspace::current();

        abort_if($workspace === null, 404, 'No current workspace.');

        return $workspace;
    }
}
