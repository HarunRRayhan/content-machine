<?php

namespace App\Http\Controllers\Videos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Videos\PublishVideoRequest;
use App\Jobs\PublishVideoJob;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PublishVideoController extends Controller
{
    public function __invoke(PublishVideoRequest $request, Video $video): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);

        abort_if($video->workspace_id !== $workspace->id, 404);

        $config = PostsyncerConfig::fromWorkspace($workspace);

        if (! $config->isReadyForPublish()) {
            return back()->withErrors([
                'publish' => __('PostSyncer is not configured for publishing.'),
            ]);
        }

        if (in_array($video->publish_state, ['queued', 'running'], true)) {
            return back()->withErrors([
                'publish' => __('A publish is already in progress.'),
            ]);
        }

        $options = array_filter([
            'when' => $request->filled('when') ? $request->input('when') : null,
            'platforms' => $request->input('platforms'),
            'confirm_ask' => $request->has('confirm_ask') ? $request->boolean('confirm_ask') : null,
        ], fn ($value) => $value !== null);

        $video->forceFill([
            'publish_state' => 'queued',
            'publish_error' => null,
        ])->save();

        PublishVideoJob::dispatch($video, $options);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => filled($options['when'] ?? null)
                ? __('Video scheduled for publishing.')
                : __('Video queued for immediate publishing.'),
        ]);

        return to_route('dashboard.videos.show', $video);
    }

    private function currentWorkspace(Request $request): Workspace
    {
        $workspace = Workspace::current();

        abort_if($workspace === null, 404, 'No current workspace.');

        return $workspace;
    }
}
