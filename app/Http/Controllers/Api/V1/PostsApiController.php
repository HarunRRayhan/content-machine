<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Posts\AttachPostDocumentAction;
use App\Actions\Posts\AttachPostImageAction;
use App\Actions\Posts\CreatePostAction;
use App\Actions\Posts\UpdatePostAction;
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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
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

        $posts = Post::query()
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($language !== null, fn ($query) => $query->where('language', $language))
            ->orderByDesc('number')
            ->orderByDesc('id')
            ->cursorPaginate(50)
            ->withQueryString();

        return PostResource::collection($posts);
    }

    public function show(string $humanId): PostResource
    {
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
            'body' => ['nullable', 'string'],
            'captions' => ['nullable', 'array'],
            'platforms' => ['nullable', 'array'],
            'status' => ['nullable', 'string', Rule::in(Post::STATUSES)],
            'idea_id' => ['nullable', 'integer'],
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
            'body' => ['sometimes', 'nullable', 'string'],
            'captions' => ['sometimes', 'nullable', 'array'],
            'platforms' => ['sometimes', 'nullable', 'array'],
            'status' => ['sometimes', 'string', Rule::in(Post::STATUSES)],
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

    /**
     * Stream a post image. Token-guarded; a media asset from another
     * workspace 404s rather than streaming.
     */
    public function media(string $humanId, MediaAsset $mediaAsset): StreamedResponse
    {
        $workspace = $this->currentWorkspace();

        $this->resolvePost($humanId);
        abort_if($mediaAsset->workspace_id !== $workspace->id, 404);

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
