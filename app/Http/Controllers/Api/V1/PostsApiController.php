<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Ids\ReserveContentIdAction;
use App\Actions\Posts\ApprovePostAction;
use App\Actions\Posts\AttachPostDocumentAction;
use App\Actions\Posts\AttachPostImageAction;
use App\Actions\Posts\AttachPostMotionAction;
use App\Actions\Posts\CreatePostAction;
use App\Actions\Posts\UpdatePostAction;
use App\Actions\Socialreins\EnqueueSocialreinsPublishAction;
use App\Actions\Socialreins\RescheduleSocialreinsAction;
use App\Data\Posts\AttachPostDocumentData;
use App\Data\Posts\AttachPostImageData;
use App\Data\Posts\AttachPostMotionData;
use App\Data\Posts\UpdatePostData;
use App\Data\Socialreins\ReschedulePostData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Posts\StorePostDocumentRequest;
use App\Http\Requests\Posts\StorePostImageRequest;
use App\Http\Requests\Posts\StorePostMotionRequest;
use App\Http\Resources\V1\PostResource;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Rules\AccessibleDriveUrl;
use App\Support\Api\IncludeFields;
use App\Support\Content\PresenceFlags;
use App\Support\Media\PostDesignTemplate;
use App\Support\Publishing\PublishException;
use App\Support\Socialreins\SocialreinsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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
                'category',
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
            'number' => ['nullable', 'integer', 'min:1', 'max:'.ReserveContentIdAction::MAX_IMPORT_NUMBER],
            'language' => ['nullable', 'string', 'max:8'],
            'slug' => ['nullable', 'string', 'max:255'],
            'template' => ['nullable', 'string', 'size:1', Rule::in(PostDesignTemplate::apiLetters())],
            'category' => ['nullable', 'string', Rule::in(Post::CATEGORIES)],
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
            'template' => ['sometimes', 'nullable', 'string', 'size:1', Rule::in(PostDesignTemplate::apiLetters())],
            'category' => ['sometimes', 'string', Rule::in(Post::CATEGORIES)],
            'body' => ['sometimes', 'nullable', 'string'],
            'captions' => ['sometimes', 'nullable', 'array'],
            'platforms' => ['sometimes', 'nullable', 'array'],
            'image_drive_urls' => ['sometimes', 'nullable', 'array'],
            'image_drive_urls.*' => ['string', 'url', 'max:2048', new AccessibleDriveUrl],
            'status' => ['sometimes', 'string', Rule::in(Post::STATUSES)],
            'blocked_reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
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

    public function attachVideo(StorePostMotionRequest $request, string $humanId, AttachPostMotionAction $action): JsonResponse
    {
        $post = $this->resolvePost($humanId);
        $alreadyAttached = $post->attachments()->count();

        /** @var UploadedFile $file */
        $file = $request->file('file');

        $user = $request->user();
        $user = $user instanceof User ? $user : null;

        $filename = basename(trim((string) ($request->input('filename') ?: $file->getClientOriginalName())));
        $filename = $filename !== '' && $filename !== '.' ? $filename : $file->getClientOriginalName();
        $order = $request->input('order');

        $post = $action->handle($post, $user, new AttachPostMotionData(
            file: $file,
            filename: $filename,
            platform: (string) $request->input('platform'),
            language: (string) $request->input('language'),
            order: $order === null ? null : (int) $order,
        ));
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

    public function publish(Request $request, string $humanId, EnqueueSocialreinsPublishAction $action): PostResource
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

    public function approve(Request $request, string $humanId, ApprovePostAction $action): PostResource
    {
        $post = $this->resolvePost($humanId);
        $user = $request->user();

        abort_unless($user instanceof User, 401, 'An API token with a user owner is required.');

        $action->handle($post, $user);

        return new PostResource($post->fresh(['attachments.mediaAsset']));
    }

    public function reschedule(
        Request $request,
        string $humanId,
        RescheduleSocialreinsAction $action,
    ): PostResource {
        $post = $this->resolvePost($humanId);

        $payload = $request->validate([
            'when' => ['required', 'string', 'max:64'],
        ]);

        try {
            $post = $action->handle(
                $post,
                $this->currentWorkspace(),
                ReschedulePostData::fromApiPayload($payload),
            );
        } catch (InvalidArgumentException|PublishException|SocialreinsException $exception) {
            throw ValidationException::withMessages([
                'when' => $exception->getMessage(),
            ]);
        }

        return new PostResource($post->load(['attachments.mediaAsset']));
    }

    /**
     * Stream a post image. Token-guarded; a media asset from another
     * workspace 404s rather than streaming.
     */
    public function media(string $humanId, MediaAsset $mediaAsset): StreamedResponse|BinaryFileResponse
    {
        $workspace = $this->currentWorkspace();

        $post = $this->resolvePost($humanId);
        abort_if($mediaAsset->workspace_id !== $workspace->id, 404);
        abort_if(! $post->attachments()->where('media_asset_id', $mediaAsset->id)->exists(), 404);

        // Video needs Range support for Safari playback; see PostsController::media.
        if (str_starts_with((string) $mediaAsset->mime, 'video/')) {
            return response()->file(Storage::disk($mediaAsset->disk)->path($mediaAsset->path), [
                'Content-Type' => $mediaAsset->mime,
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

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

        $post = Post::query()->where('workspace_id', $workspace->id)->whereLabel($humanId)->firstOrFail();

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
