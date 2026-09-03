<?php

namespace App\Http\Controllers\Posts;

use App\Actions\Posts\ApprovePostAction;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ApprovePostController extends Controller
{
    public function __invoke(Request $request, Post $post, ApprovePostAction $action): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);

        abort_if($post->workspace_id !== $workspace->id, 404);

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $action->handle($post, $user);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Post approved for publishing.'),
        ]);

        return to_route('posts.show', $post);
    }

    private function currentWorkspace(Request $request): Workspace
    {
        $workspace = Workspace::current();

        abort_if($workspace === null, 404, 'No current workspace.');

        return $workspace;
    }
}
