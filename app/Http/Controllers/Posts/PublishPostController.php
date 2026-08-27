<?php

namespace App\Http\Controllers\Posts;

use App\Actions\Postsyncer\EnqueuePostPublishAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Posts\PublishPostRequest;
use App\Models\Post;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PublishPostController extends Controller
{
    public function __invoke(PublishPostRequest $request, Post $post, EnqueuePostPublishAction $action): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);

        $options = array_filter([
            'when' => $request->filled('when') ? $request->input('when') : null,
            'platforms' => $request->input('platforms'),
            'confirm_ask' => $request->has('confirm_ask') ? $request->boolean('confirm_ask') : null,
        ], fn ($value) => $value !== null);

        $action->handle($post, $workspace, $options);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => filled($options['when'] ?? null)
                ? __('Post scheduled for publishing.')
                : __('Post queued for immediate publishing.'),
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
