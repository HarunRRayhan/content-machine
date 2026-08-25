<?php

namespace App\Http\Controllers\Videos;

use App\Actions\Videos\UpdateVideoAction;
use App\Data\Videos\UpdateVideoData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Videos\UpdateVideoRequest;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Content\NormalizeCaptions;
use App\Support\Postsyncer\PostsyncerConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VideosController extends Controller
{
    /**
     * List the current workspace's videos, newest first. Filterable by
     * status, language, and a free-text query over title / human id.
     */
    public function index(Request $request): Response
    {
        $workspace = $this->currentWorkspace($request);

        $status = $request->string('status')->toString() ?: null;
        $language = $request->string('language')->toString() ?: null;
        $query = $request->string('q')->toString() ?: null;

        $videos = Video::query()
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
            ->through(fn (Video $video) => $this->presentSummary($video));

        return Inertia::render('videos/index', [
            'videos' => $videos,
            'filters' => [
                'status' => $status,
                'language' => $language,
                'q' => $query,
            ],
            'statuses' => Video::STATUSES,
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
            'number' => $video->number,
            'title' => $video->title,
            'status' => $video->status,
            'language' => $video->language,
            'has_script' => filled($video->script_markdown),
            'has_captions' => ! empty($video->captions),
            'has_deck' => ! empty($video->deck_manifest),
            'created_at' => $video->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDetail(Video $video): array
    {
        $workspace = Workspace::current();
        $postsyncerConfig = $workspace !== null
            ? PostsyncerConfig::fromWorkspace($workspace)
            : null;

        return [
            'id' => $video->id,
            'human_id' => $video->human_id,
            'number' => $video->number,
            'title' => $video->title,
            'body' => $video->body,
            'script_markdown' => $video->script_markdown,
            'captions' => NormalizeCaptions::forDashboard($video->captions),
            'deck_manifest' => $video->deck_manifest,
            'has_deck' => ! empty($video->deck_manifest),
            'video_drive_url' => $video->video_drive_url,
            'cover_drive_url' => $video->cover_drive_url,
            'language' => $video->language,
            'slug' => $video->slug,
            'status' => $video->status,
            'publish_state' => $video->publish_state,
            'publish_error' => $video->publish_error,
            'postsyncer' => $video->postsyncer,
            'postsyncer_ready' => $postsyncerConfig?->isReadyForPublish() ?? false,
            'idea_id' => $video->idea_id,
            'created_at' => $video->created_at?->toIso8601String(),
            'updated_at' => $video->updated_at?->toIso8601String(),
        ];
    }
}
