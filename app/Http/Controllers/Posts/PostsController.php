<?php

namespace App\Http\Controllers\Posts;

use App\Actions\Posts\UpdatePostAction;
use App\Data\Posts\UpdatePostData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Posts\UpdatePostRequest;
use App\Models\Post;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PostsController extends Controller
{
    /**
     * List the current workspace's posts, newest first.
     */
    public function index(Request $request): Response
    {
        $workspace = $this->currentWorkspace($request);

        $posts = Post::query()
            ->where('workspace_id', $workspace->id)
            ->latest()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Post $post) => $this->presentSummary($post));

        return Inertia::render('posts/index', [
            'posts' => $posts,
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
            'title' => $post->title,
            'status' => $post->status,
            'created_at' => $post->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDetail(Post $post): array
    {
        return [
            'id' => $post->id,
            'human_id' => $post->human_id,
            'title' => $post->title,
            'body' => $post->body,
            'status' => $post->status,
            'idea_id' => $post->idea_id,
            'created_at' => $post->created_at?->toIso8601String(),
        ];
    }
}
