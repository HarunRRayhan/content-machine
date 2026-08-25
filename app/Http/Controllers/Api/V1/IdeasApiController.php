<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Ideas\CreateIdeaAction;
use App\Actions\Ideas\UpdateIdeaAction;
use App\Data\Ideas\UpdateIdeaData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ideas\UpdateIdeaRequest;
use App\Http\Resources\V1\IdeaResource;
use App\Models\Idea;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * CRUD access to a workspace's ideas for API clients. POST supports
 * idempotent import via human_id (PI-20 / VI-27). human_id
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
            ->orderByDesc('id')
            ->cursorPaginate(50)
            ->withQueryString();

        return IdeaResource::collection($ideas);
    }


    public function store(Request $request, CreateIdeaAction $action): JsonResponse
    {
        $workspace = $this->currentWorkspace();

        $payload = $request->validate([
            'kind' => ['required', 'string', Rule::in(['post', 'video', 'feature'])],
            'title' => ['required', 'string', 'max:255'],
            'human_id' => ['nullable', 'string', 'max:32'],
            'number' => ['nullable', 'integer', 'min:1'],
            'slug' => ['nullable', 'string', 'max:255'],
            'score' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'trend' => ['nullable', 'string', 'max:64'],
            'rationale' => ['nullable', 'string'],
            'body' => ['nullable', 'string'],
            'editorial_type' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'string', Rule::in(['open', 'promoted', 'dropped'])],
            'details' => ['nullable', 'array'],
        ]);

        $idea = $action->handle($workspace, $payload);

        return (new IdeaResource($idea))
            ->response()
            ->setStatusCode($idea->wasRecentlyCreated ? 201 : 200);
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
