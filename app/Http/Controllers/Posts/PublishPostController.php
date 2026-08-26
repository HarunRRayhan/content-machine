<?php

namespace App\Http\Controllers\Posts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Posts\PublishPostRequest;
use App\Jobs\PublishPostJob;
use App\Models\Post;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PublishPostController extends Controller
{
    public function __invoke(PublishPostRequest $request, Post $post): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);

        abort_if($post->workspace_id !== $workspace->id, 404);

        $config = PostsyncerConfig::fromWorkspace($workspace);

        if (! $config->isReadyForPublish()) {
            return back()->withErrors([
                'publish' => __('PostSyncer is not configured for publishing.'),
            ]);
        }

        if (in_array($post->publish_state, ['queued', 'running'], true)) {
            return back()->withErrors([
                'publish' => __('A publish is already in progress.'),
            ]);
        }

        $options = array_filter([
            'when' => $request->filled('when') ? $request->input('when') : null,
            'platforms' => $request->input('platforms'),
            'confirm_ask' => $request->has('confirm_ask') ? $request->boolean('confirm_ask') : null,
        ], fn ($value) => $value !== null);

        $post->forceFill([
            'publish_state' => 'queued',
            'publish_error' => null,
        ])->save();

        PublishPostJob::dispatch($post, $options);

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
