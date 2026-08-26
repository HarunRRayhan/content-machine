<?php

namespace App\Http\Controllers\Posts;

use App\Actions\Posts\UpdatePostAction;
use App\Data\Posts\UpdatePostData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Posts\UpdatePostRequest;
use App\Models\Idea;
use App\Models\Post;
use App\Models\Workspace;
use App\Support\Content\NormalizeCaptions;
use App\Support\Postsyncer\PostPublishPlanner;
use App\Support\Postsyncer\PostsyncerConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class PostsController extends Controller
{
    /**
     * Studio-like status tabs on the posts index. Ideation lists open
     * post ideas; every other tab filters posts by pipeline status.
     *
     * @var list<string>
     */
    public const TAB_STATUSES = [
        'ideation',
        'draft',
        'ready',
        'scheduled',
        'posted',
        'archived',
        'dropped',
    ];

    /**
     * List the current workspace's posts (or post ideas on the Ideation
     * tab), newest first. Filterable by status tab, language, and a
     * free-text query over title / human id.
     */
    public function index(Request $request): Response
    {
        $workspace = $this->currentWorkspace($request);

        $status = $request->string('status')->toString() ?: 'draft';
        $language = $request->string('language')->toString() ?: null;
        $query = $request->string('q')->toString() ?: null;

        if ($status === 'ideation') {
            $items = Idea::query()
                ->where('workspace_id', $workspace->id)
                ->where('kind', 'post')
                ->where('status', 'open')
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
                ->through(fn (Idea $idea) => $this->presentIdeaSummary($idea));
        } else {
            $items = Post::query()
                ->where('workspace_id', $workspace->id)
                ->when($status === 'draft', fn ($builder) => $builder->whereIn('status', ['draft', 'ready']))
                ->when($status === 'archived', fn ($builder) => $builder->whereIn('status', ['archived', 'dropped']))
                ->when(in_array($status, ['scheduled', 'posted', 'ready'], true), fn ($builder) => $builder->where('status', $status))
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
        }

        return Inertia::render('posts/index', [
            'items' => $items,
            'filters' => [
                'status' => $status,
                'language' => $language,
                'q' => $query,
            ],
            'counts' => $this->statusCounts($workspace),
            'tabs' => self::TAB_STATUSES,
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
     * @return array<string, int>
     */
    private function statusCounts(Workspace $workspace): array
    {
        $postCounts = Post::query()
            ->where('workspace_id', $workspace->id)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $counts = [
            'ideation' => Idea::query()
                ->where('workspace_id', $workspace->id)
                ->where('kind', 'post')
                ->where('status', 'open')
                ->count(),
        ];

        foreach (Post::STATUSES as $postStatus) {
            $counts[$postStatus] = (int) ($postCounts[$postStatus] ?? 0);
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentIdeaSummary(Idea $idea): array
    {
        return [
            'type' => 'idea',
            'id' => $idea->id,
            'human_id' => $idea->human_id,
            'title' => $idea->title,
            'score' => $idea->score,
            'trend' => $idea->trend,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentSummary(Post $post): array
    {
        return [
            'type' => 'post',
            'id' => $post->id,
            'human_id' => $post->human_id,
            'number' => $post->number,
            'title' => $post->title,
            'status' => $post->status,
            'publish_state' => $post->publish_state,
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

        $imageUrls = [];
        foreach ($images as $image) {
            if (! empty($image['filename']) && ! empty($image['url'])) {
                $imageUrls[$image['filename']] = $image['url'];
                $basename = basename($image['filename']);
                $imageUrls[$basename] = $image['url'];
            }
        }

        $workspace = Workspace::current();
        $postsyncerConfig = $workspace !== null
            ? PostsyncerConfig::fromWorkspace($workspace)
            : null;

        return [
            'id' => $post->id,
            'human_id' => $post->human_id,
            'number' => $post->number,
            'title' => $post->title,
            'body' => $post->body,
            'captions' => NormalizeCaptions::forDashboard($post->captions),
            'platforms' => $post->platforms ?? [],
            'images' => $images,
            'image_urls' => $imageUrls,
            'caption_image_names' => array_keys($captionImageNames),
            'image_drive_urls' => $post->image_drive_urls ?? [],
            'language' => $post->language,
            'slug' => $post->slug,
            'status' => $post->status,
            'publish_state' => $post->publish_state,
            'publish_error' => $post->publish_error,
            'postsyncer' => $post->postsyncer,
            'postsyncer_ready' => $postsyncerConfig?->isReadyForPublish() ?? false,
            'needs_confirm_ask' => $postsyncerConfig !== null
                && app(PostPublishPlanner::class)->needsConfirmAsk($post, $postsyncerConfig),
            'idea_id' => $post->idea_id,
            'created_at' => $post->created_at?->toIso8601String(),
            'updated_at' => $post->updated_at?->toIso8601String(),
        ];
    }
}
