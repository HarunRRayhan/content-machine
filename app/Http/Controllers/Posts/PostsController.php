<?php

namespace App\Http\Controllers\Posts;

use App\Actions\Posts\UpdatePostAction;
use App\Data\Posts\UpdatePostData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Posts\UpdatePostRequest;
use App\Models\Post;
use App\Models\Workspace;
use App\Support\Content\NormalizeCaptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class PostsController extends Controller
{
    /**
     * List the current workspace's posts, newest first. Filterable by
     * status, language, and a free-text query over title / human id.
     */
    public function index(Request $request): Response
    {
        $workspace = $this->currentWorkspace($request);

        $status = $request->string('status')->toString() ?: null;
        $language = $request->string('language')->toString() ?: null;
        $query = $request->string('q')->toString() ?: null;

        $posts = Post::query()
            ->where('workspace_id', $workspace->id)
            ->when($status, fn ($builder) => $builder->where('status', $status))
            ->when($language, fn ($builder) => $builder->where('language', $language))
            ->when($query, function ($builder) use ($query) {
                $like = '%'.$query.'%';
                $builder->where(function ($inner) use ($like) {
                    $inner->where('title', 'ilike', $like)
                        ->orWhere('human_id', 'ilike', $like)
                        ->orWhere('slug', 'ilike', $like);
                });
            })
            ->latest()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Post $post) => $this->presentSummary($post));

        return Inertia::render('posts/index', [
            'posts' => $posts,
            'filters' => [
                'status' => $status,
                'language' => $language,
                'q' => $query,
            ],
            'statuses' => Post::STATUSES,
        ]);
    }

    /**
     * Show a single post. 404s if it's not in the current workspace so a
     * request can't view another workspace's post by guessing an id.
     */
    public function show(Request $request, Post $post): Response
    {
        $workspace = $this->currentWorkspace($request);

        abort_if($post->workspace_id !== $workspace->id, 404);

        $post->load(['attachments.mediaAsset']);

        return Inertia::render('posts/show', [
            'post' => $this->presentDetail($post),
        ]);
    }

    public function update(UpdatePostRequest $request, Post $post, UpdatePostAction $updatePostAction): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);

        abort_if($post->workspace_id !== $workspace->id, 404);

        $updatePostAction->handle($post, UpdatePostData::fromRequest($request));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Post updated.'),
        ]);

        return to_route('dashboard.posts.show', $post);
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
    private function presentSummary(Post $post): array
    {
        return [
            'id' => $post->id,
            'human_id' => $post->human_id,
            'number' => $post->number,
            'title' => $post->title,
            'status' => $post->status,
            'language' => $post->language,
            'platforms' => $post->platforms ?? [],
            'has_captions' => ! empty($post->captions),
            'has_body' => filled($post->body),
            'created_at' => $post->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDetail(Post $post): array
    {
        $images = $post->attachments
            ->map(function ($attachment) {
                $media = $attachment->mediaAsset;
                if ($media === null) {
                    return null;
                }

                $url = null;
                try {
                    $url = Storage::disk($media->disk)->url($media->path);
                } catch (\Throwable) {
                    $url = null;
                }

                return [
                    'id' => $attachment->id,
                    'role' => $attachment->role,
                    'platform' => $attachment->platform,
                    'filename' => $media->original_filename,
                    'url' => $url,
                    'mime' => $media->mime,
                ];
            })
            ->filter()
            ->values()
            ->all();

        // Until images are uploaded as attachments, still surface filenames
        // referenced inside caption blocks so the Images tab isn't empty.
        $captionImageNames = [];
        foreach (NormalizeCaptions::forDashboard($post->captions) as $group) {
            foreach ($group['platforms'] as $platform) {
                foreach ($platform['images'] as $name) {
                    $captionImageNames[$name] = true;
                }
            }
        }

        return [
            'id' => $post->id,
            'human_id' => $post->human_id,
            'number' => $post->number,
            'title' => $post->title,
            'body' => $post->body,
            'captions' => NormalizeCaptions::forDashboard($post->captions),
            'platforms' => $post->platforms ?? [],
            'images' => $images,
            'caption_image_names' => array_keys($captionImageNames),
            'language' => $post->language,
            'slug' => $post->slug,
            'status' => $post->status,
            'idea_id' => $post->idea_id,
            'created_at' => $post->created_at?->toIso8601String(),
            'updated_at' => $post->updated_at?->toIso8601String(),
        ];
    }
}
