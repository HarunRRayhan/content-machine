<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Postsyncer\EnqueueVideoPublishAction;
use App\Actions\Videos\CreateVideoAction;
use App\Actions\Videos\UpdateVideoAction;
use App\Data\Videos\UpdateVideoData;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\VideoResource;
use App\Models\Video;
use App\Models\Workspace;
use App\Rules\AccessibleDriveUrl;
use App\Rules\RenderablePresentationManifest;
use App\Support\Api\IncludeFields;
use App\Support\Content\PresenceFlags;
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
        $include = IncludeFields::fromRequest($request);
        $request->attributes->set('api_include', $include);

        $query = Video::query()
            ->when($status !== null, fn ($builder) => $builder->where('status', $status))
            ->when($language !== null, fn ($builder) => $builder->where('language', $language))
            ->orderByDesc('number')
            ->orderByDesc('id');

        if ($include->isSlim()) {
            PresenceFlags::selectVideoSummary($query, [
                'id',
                'workspace_id',
                'idea_id',
                'number',
                'human_id',
                'title',
                'language',
                'slug',
                'body',
                'video_drive_url',
                'cover_drive_url',
                'postsyncer',
                'publish_state',
                'publish_error',
                'status',
                'created_at',
                'updated_at',
            ]);
        }

        $videos = $query
            ->cursorPaginate(50)
            ->withQueryString();

        return VideoResource::collection($videos);
    }

    public function show(string $humanId): VideoResource
    {
        request()->attributes->set('api_include', IncludeFields::full());

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
            'deck_manifest' => ['nullable', 'array', new RenderablePresentationManifest],
            'video_drive_url' => ['nullable', 'string', 'url', 'max:2048', new AccessibleDriveUrl],
            'cover_drive_url' => ['nullable', 'string', 'url', 'max:2048', new AccessibleDriveUrl],
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
            'deck_manifest' => ['sometimes', 'nullable', 'array', new RenderablePresentationManifest],
            'video_drive_url' => ['sometimes', 'nullable', 'string', 'url', 'max:2048', new AccessibleDriveUrl],
            'cover_drive_url' => ['sometimes', 'nullable', 'string', 'url', 'max:2048', new AccessibleDriveUrl],
            'status' => ['sometimes', 'string', Rule::in(Video::STATUSES)],
            'postsyncer' => ['sometimes', 'nullable', 'array'],
            'postsyncer.groups' => ['sometimes', 'array'],
            'publish_state' => ['sometimes', 'nullable', 'string', Rule::in(Video::PUBLISH_STATES)],
            'publish_error' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        $action->handle($video, UpdateVideoData::fromApiPayload($payload, $video));

        return new VideoResource($video->fresh());
    }

    public function publish(Request $request, string $humanId, EnqueueVideoPublishAction $action): VideoResource
    {
        $video = $this->resolveVideo($humanId);

        $payload = $request->validate([
            'when' => ['nullable', 'string', 'max:64'],
            'platforms' => ['nullable', 'array'],
            'platforms.*' => ['string', 'max:64'],
            'confirm_ask' => ['nullable', 'boolean'],
        ]);

        $options = array_filter([
            'when' => $payload['when'] ?? null,
            'platforms' => $payload['platforms'] ?? null,
            'confirm_ask' => array_key_exists('confirm_ask', $payload)
                ? (bool) $payload['confirm_ask']
                : null,
        ], fn ($value) => $value !== null);

        $video = $action->handle($video, $this->currentWorkspace(), $options);

        return new VideoResource($video);
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
