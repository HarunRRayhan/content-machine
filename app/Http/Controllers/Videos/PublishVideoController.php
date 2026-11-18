<?php

namespace App\Http\Controllers\Videos;

use App\Actions\Postsyncer\EnqueueVideoPublishAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Videos\PublishVideoRequest;
use App\Models\Video;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PublishVideoController extends Controller
{
    public function __invoke(PublishVideoRequest $request, Video $video, EnqueueVideoPublishAction $action): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);

        $options = array_filter([
            'when' => $request->filled('when') ? $request->input('when') : null,
            'platforms' => $request->input('platforms'),
            'confirm_ask' => $request->has('confirm_ask') ? $request->boolean('confirm_ask') : null,
        ], fn ($value) => $value !== null);

        $action->handle($video, $workspace, $options);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => filled($options['when'] ?? null)
                ? __('Video scheduled for publishing.')
                : __('Video queued for immediate publishing.'),
        ]);

        return to_route('videos.show', $video);
    }

    private function currentWorkspace(Request $request): Workspace
    {
        $workspace = Workspace::current();

        abort_if($workspace === null, 404, 'No current workspace.');

        return $workspace;
    }
}
