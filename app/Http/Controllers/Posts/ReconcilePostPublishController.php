<?php

namespace App\Http\Controllers\Posts;

use App\Actions\Postsyncer\PublishPostAction;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ReconcilePostPublishController extends Controller
{
    public function __invoke(
        Request $request,
        Post $post,
        PublishPostAction $action,
    ): RedirectResponse {
        $workspace = $this->currentWorkspace();

        abort_if($post->workspace_id !== $workspace->id, 404);

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

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('PostSyncer post reconciled. Retry the publish to continue.'),
        ]);

        return to_route('posts.show', $post);
    }

    private function currentWorkspace(): Workspace
    {
        $workspace = Workspace::current();

        abort_if($workspace === null, 404, 'No current workspace.');

        return $workspace;
    }
}
