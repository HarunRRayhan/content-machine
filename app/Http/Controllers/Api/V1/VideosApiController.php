<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Videos\CreateVideoAction;
use App\Actions\Videos\UpdateVideoAction;
use App\Data\Videos\UpdateVideoData;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\VideoResource;
use App\Models\Video;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * CRUD for videos over the workspace token API. human_id (V-12 / BV-53)
 * is the stable address personal-content already uses.
 */
class VideosApiController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->currentWorkspace();

        $status = $request->string('status')->toString() ?: null;
        $language = $request->string('language')->toString() ?: null;

        $videos = Video::query()
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($language !== null, fn ($query) => $query->where('language', $language))
            ->orderByDesc('number')
            ->cursorPaginate(50)
            ->withQueryString();

        return VideoResource::collection($videos);
    }

    public function show(string $humanId): VideoResource
    {
        return new VideoResource($this->resolveVideo($humanId));
    }

    public function store(Request $request, CreateVideoAction $action): JsonResponse
    {
        $workspace = $this->currentWorkspace();

        $payload = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'human_id' => ['nullable', 'string', 'max:32'],
            'number' => ['nullable', 'integer', 'min:1'],
            'language' => ['nullable', 'string', 'max:8'],
            'slug' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'script_markdown' => ['nullable', 'string'],
            'captions' => ['nullable', 'array'],
            'deck_manifest' => ['nullable', 'array'],
            'status' => ['nullable', 'string', Rule::in(Video::STATUSES)],
            'idea_id' => ['nullable', 'integer'],
        ]);

        $video = $action->handle($workspace, $payload);

        return (new VideoResource($video))
            ->response()
            ->setStatusCode($video->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $request, string $humanId, UpdateVideoAction $action): VideoResource
    {
        $video = $this->resolveVideo($humanId);

        $payload = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'language' => ['sometimes', 'nullable', 'string', 'max:8'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255'],
            'body' => ['sometimes', 'nullable', 'string'],
            'script_markdown' => ['sometimes', 'nullable', 'string'],
            'captions' => ['sometimes', 'nullable', 'array'],
            'deck_manifest' => ['sometimes', 'nullable', 'array'],
            'status' => ['sometimes', 'string', Rule::in(Video::STATUSES)],
        ]);

        $action->handle($video, UpdateVideoData::fromApiPayload($payload, $video));

        return new VideoResource($video->fresh());
    }

    private function resolveVideo(string $humanId): Video
    {
        $workspace = $this->currentWorkspace();

        $video = Video::query()->where('human_id', $humanId)->firstOrFail();

        abort_if($video->workspace_id !== $workspace->id, 404);

        return $video;
    }

    private function currentWorkspace(): Workspace
    {
        $workspace = Workspace::current();

        abort_if($workspace === null, 404, 'No current workspace.');

        return $workspace;
    }
}
