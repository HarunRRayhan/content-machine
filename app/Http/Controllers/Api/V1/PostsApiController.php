<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Posts\AttachPostDocumentAction;
use App\Actions\Posts\AttachPostImageAction;
use App\Actions\Posts\CreatePostAction;
use App\Actions\Posts\UpdatePostAction;
use App\Actions\Postsyncer\EnqueuePostPublishAction;
use App\Actions\Postsyncer\PublishPostAction;
use App\Data\Posts\AttachPostDocumentData;
use App\Data\Posts\AttachPostImageData;
use App\Data\Posts\UpdatePostData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Posts\StorePostDocumentRequest;
use App\Http\Requests\Posts\StorePostImageRequest;
use App\Http\Resources\V1\PostResource;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Rules\AccessibleDriveUrl;
use App\Support\Api\IncludeFields;
use App\Support\Content\PresenceFlags;
use App\Support\Postsyncer\PostsyncerException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CRUD for posts over the workspace token API.
 */
class PostsApiController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->currentWorkspace();

        $status = $request->string('status')->toString() ?: null;
        $language = $request->string('language')->toString() ?: null;
        $include = IncludeFields::fromRequest($request);
        $request->attributes->set('api_include', $include);

        $query = Post::query()
            ->when($status !== null, fn ($builder) => $builder->where('status', $status))
            ->when($language !== null, fn ($builder) => $builder->where('language', $language))
            ->orderByDesc('number')
            ->orderByDesc('id');

        if ($include->isSlim()) {
            PresenceFlags::selectPostSummary($query, [
                'id',
                'workspace_id',
                'idea_id',
                'number',
                'human_id',
                'title',
                'language',
                'slug',
                'template',
                'platforms',
                'image_drive_urls',
                'postsyncer',
                'publish_state',
                'publish_error',
                'publish_progress',
                'approval_state',
                'status',
                'created_at',
                'updated_at',
            ]);
        }

        $posts = $query
            ->cursorPaginate(50)
            ->withQueryString();

        return PostResource::collection($posts);
    }

    public function show(string $humanId): PostResource
    {
        request()->attributes->set('api_include', IncludeFields::full());

        return new PostResource($this->resolvePost($humanId)->load(['attachments.mediaAsset']));
    }

    public function store(Request $request, CreatePostAction $action): JsonResponse
    {
        $workspace = $this->currentWorkspace();

        $payload = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'human_id' => ['nullable', 'string', 'max:32'],
            'number' => ['nullable', 'integer', 'min:1'],
            'language' => ['nullable', 'string', 'max:8'],
            'slug' => ['nullable', 'string', 'max:255'],
            'template' => ['nullable', 'string', 'size:1', Rule::in(['A', 'B', 'C', 'D', 'E', 'F', 'a', 'b', 'c', 'd', 'e', 'f'])],
            'body' => ['nullable', 'string'],
            'captions' => ['nullable', 'array'],
            'platforms' => ['nullable', 'array'],
            'image_drive_urls' => ['nullable', 'array'],
            'image_drive_urls.*' => ['string', 'url', 'max:2048', new AccessibleDriveUrl],
            'status' => ['nullable', 'string', Rule::in(Post::STATUSES)],
            'idea_id' => [
                'nullable',
                'integer',
                Rule::exists('ideas', 'id')->where('workspace_id', $workspace->id),
            ],
        ]);

        $post = $action->handle($workspace, $payload);

        return (new PostResource($post))
            ->response()
            ->setStatusCode($post->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $request, string $humanId, UpdatePostAction $action): PostResource
    {
        $post = $this->resolvePost($humanId);

        $payload = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'language' => ['sometimes', 'nullable', 'string', 'max:8'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255'],
            'template' => ['sometimes', 'nullable', 'string', 'size:1', Rule::in(['A', 'B', 'C', 'D', 'E', 'F', 'a', 'b', 'c', 'd', 'e', 'f'])],
            'body' => ['sometimes', 'nullable', 'string'],
            'captions' => ['sometimes', 'nullable', 'array'],
            'platforms' => ['sometimes', 'nullable', 'array'],
            'image_drive_urls' => ['sometimes', 'nullable', 'array'],
            'image_drive_urls.*' => ['string', 'url', 'max:2048', new AccessibleDriveUrl],
            'status' => ['sometimes', 'string', Rule::in(Post::STATUSES)],
            'postsyncer' => ['prohibited'],
            'publish_state' => ['prohibited'],
            'publish_error' => ['prohibited'],
        ]);

        $action->handle($post, UpdatePostData::fromApiPayload($payload, $post));

        return new PostResource($post->fresh(['attachments.mediaAsset']));
    }

    public function attachImage(StorePostImageRequest $request, string $humanId, AttachPostImageAction $action): JsonResponse
    {
        $post = $this->resolvePost($humanId);
        $alreadyAttached = $post->attachments()->count();

        /** @var UploadedFile $file */
        $file = $request->file('image');

        $user = $request->user();
        $user = $user instanceof User ? $user : null;

        $post = $action->handle($post, $user, AttachPostImageData::fromApi($file));
        $fresh = $post->fresh(['attachments.mediaAsset']) ?? $post;
        $created = $fresh->attachments()->count() > $alreadyAttached;

        return (new PostResource($fresh))
            ->response()
            ->setStatusCode($created ? 201 : 200);
    }

    public function attachDocument(StorePostDocumentRequest $request, string $humanId, AttachPostDocumentAction $action): JsonResponse
    {
        $post = $this->resolvePost($humanId);
        $alreadyAttached = $post->attachments()->count();

        /** @var UploadedFile $file */
        $file = $request->file('document');

        $user = $request->user();
        $user = $user instanceof User ? $user : null;

        $post = $action->handle($post, $user, AttachPostDocumentData::fromApi($file));
        $fresh = $post->fresh(['attachments.mediaAsset']) ?? $post;
        $created = $fresh->attachments()->count() > $alreadyAttached;

        return (new PostResource($fresh))
            ->response()
            ->setStatusCode($created ? 201 : 200);
    }

    public function publish(Request $request, string $humanId, EnqueuePostPublishAction $action): PostResource
    {
        $post = $this->resolvePost($humanId);

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

        $post = $action->handle($post, $this->currentWorkspace(), $options);

        return new PostResource($post->load(['attachments.mediaAsset']));
    }

    public function reconcile(Request $request, string $humanId, PublishPostAction $action): PostResource
    {
        $post = $this->resolvePost($humanId);

        $payload = $request->validate([
            'postsyncer_id' => ['required', 'string', 'max:255'],
        ]);

        try {
            $action->reconcile($post, $payload['postsyncer_id']);
        } catch (PostsyncerException $exception) {
            throw ValidationException::withMessages([
                'postsyncer_id' => $exception->getMessage(),
            ]);
        }

        return new PostResource($post->fresh(['attachments.mediaAsset']));
    }

    public function reconcileMedia(Request $request, string $humanId, PublishPostAction $action): PostResource
    {
        $post = $this->resolvePost($humanId);

        $payload = $request->validate([
            'media_ids' => ['required', 'array', 'min:1'],
            'media_ids.*' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $action->reconcileMedia($post, $payload['media_ids']);
        } catch (PostsyncerException $exception) {
            throw ValidationException::withMessages([
                'media_ids' => $exception->getMessage(),
            ]);
        }

        return new PostResource($post->fresh(['attachments.mediaAsset']));
    }

    /**
     * Stream a post image. Token-guarded; a media asset from another
     * workspace 404s rather than streaming.
     */
    public function media(string $humanId, MediaAsset $mediaAsset): StreamedResponse
    {
        $workspace = $this->currentWorkspace();

        $post = $this->resolvePost($humanId);
        abort_if($mediaAsset->workspace_id !== $workspace->id, 404);
        abort_if(! $post->attachments()->where('media_asset_id', $mediaAsset->id)->exists(), 404);

        return Storage::disk($mediaAsset->disk)->response(
            $mediaAsset->path,
            $mediaAsset->original_filename,
            [
                'Content-Type' => $mediaAsset->mime,
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    private function resolvePost(string $humanId): Post
    {
        $workspace = $this->currentWorkspace();

        $post = Post::query()->where('human_id', $humanId)->firstOrFail();

        abort_if($post->workspace_id !== $workspace->id, 404);

        return $post;
    }

    private function currentWorkspace(): Workspace
    {
        $workspace = Workspace::current();

        abort_if($workspace === null, 404, 'No current workspace.');

        return $workspace;
    }
}
