<?php

namespace App\Http\Controllers\Videos;

use App\Actions\Videos\UpdateVideoAction;
use App\Data\Videos\UpdateVideoData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Videos\UpdateVideoRequest;
use App\Models\Video;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VideosController extends Controller
{
    /**
     * List the current workspace's videos, newest first.
     */
    public function index(Request $request): Response
    {
        $workspace = $this->currentWorkspace($request);

        $videos = Video::query()
            ->where('workspace_id', $workspace->id)
            ->latest()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Video $video) => $this->presentSummary($video));

        return Inertia::render('videos/index', [
            'videos' => $videos,
        ]);
    }

    /**
     * Show a single video. 404s if it's not in the current workspace so a
     * request can't view another workspace's video by guessing an id.
     */
    public function show(Request $request, Video $video): Response
    {
        $workspace = $this->currentWorkspace($request);

        abort_if($video->workspace_id !== $workspace->id, 404);

        return Inertia::render('videos/show', [
            'video' => $this->presentDetail($video),
        ]);
    }

    public function update(UpdateVideoRequest $request, Video $video, UpdateVideoAction $updateVideoAction): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);

        abort_if($video->workspace_id !== $workspace->id, 404);

        $updateVideoAction->handle($video, UpdateVideoData::fromRequest($request));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Video updated.'),
        ]);

        return to_route('dashboard.videos.show', $video);
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
    private function presentSummary(Video $video): array
    {
        return [
            'id' => $video->id,
            'human_id' => $video->human_id,
            'title' => $video->title,
            'status' => $video->status,
            'created_at' => $video->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDetail(Video $video): array
    {
        return [
            'id' => $video->id,
            'human_id' => $video->human_id,
            'title' => $video->title,
            'body' => $video->body,
            'status' => $video->status,
            'idea_id' => $video->idea_id,
            'created_at' => $video->created_at?->toIso8601String(),
        ];
    }
}
