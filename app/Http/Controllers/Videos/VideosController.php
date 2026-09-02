<?php

namespace App\Http\Controllers\Videos;

use App\Actions\Videos\UpdateVideoAction;
use App\Data\Videos\UpdateVideoData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Videos\UpdateVideoRequest;
use App\Models\Idea;
use App\Models\MediaAsset;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Content\NormalizeCaptions;
use App\Support\Content\ParseVideoScript;
use App\Support\Content\PresenceFlags;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Postsyncer\VideoPublishPlanner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VideosController extends Controller
{
    /**
     * Studio-like status tabs on the videos index. Ideation lists open
     * video ideas; every other tab filters videos by pipeline status.
     *
     * @var list<string>
     */
    public const TAB_STATUSES = [
        'ideation',
        'pending',
        'ready',
        'recorded',
        'scheduled',
        'posted',
        'archived',
        'draft',
        'dropped',
    ];

    /**
     * List the current workspace's videos (or video ideas on the Ideation
     * tab). Videos are ordered by custom number (BV-67, BV-66, …), highest
     * first, matching the API and git tracker. Ideas are highest score first,
     * with null scores last. Filterable by status tab, language, and a
     * free-text query over title / human id.
     */
    public function index(Request $request): Response
    {
        $workspace = $this->currentWorkspace($request);

        $status = $request->string('status')->toString() ?: 'pending';
        $language = $request->string('language')->toString() ?: null;
        $search = $request->string('q')->toString() ?: null;

        if ($status === 'ideation') {
            $items = Idea::query()
                ->where('workspace_id', $workspace->id)
                ->where('kind', 'video')
                ->where('status', 'open')
                ->when($search, function ($builder) use ($search) {
                    $like = '%'.$search.'%';
                    $builder->where(function ($inner) use ($like) {
                        $inner->where('title', 'ilike', $like)
                            ->orWhere('human_id', 'ilike', $like)
                            ->orWhere('slug', 'ilike', $like);
                    });
                })
                ->orderByRaw('score DESC NULLS LAST')
                ->orderBy('title')
                ->paginate(20)
                ->withQueryString()
                ->through(fn (Idea $idea) => $this->presentIdeaSummary($idea));
        } else {
            $videosQuery = Video::query()
                ->where('workspace_id', $workspace->id)
                ->where('status', $status)
                ->when($language, fn ($builder) => $builder->where('language', $language))
                ->when($search, function ($builder) use ($search) {
                    $like = '%'.$search.'%';
                    $builder->where(function ($inner) use ($like) {
                        $inner->where('title', 'ilike', $like)
                            ->orWhere('human_id', 'ilike', $like)
                            ->orWhere('slug', 'ilike', $like);
                    });
                })
                ->orderByDesc('number')
                ->orderByDesc('id');

            PresenceFlags::selectVideoSummary($videosQuery, [
                'id',
                'workspace_id',
                'number',
                'human_id',
                'title',
                'status',
                'publish_state',
                'language',
                'created_at',
            ]);

            $items = $videosQuery
                ->paginate(20)
                ->withQueryString()
                ->through(fn (Video $video) => $this->presentSummary($video));
        }

        return Inertia::render('videos/index', [
            'items' => $items,
            'filters' => [
                'status' => $status,
                'language' => $language,
                'q' => $search,
            ],
            'counts' => $this->statusCounts($workspace),
            'tabs' => self::TAB_STATUSES,
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

        $video->load(['attachments.mediaAsset']);

        return Inertia::render('videos/show', [
            'video' => $this->presentDetail($video),
        ]);
    }

    /**
     * Stream a video image. The scratchpad disk is private, so this is the
     * only way the dashboard <img> can load one: same-origin + session
     * cookie. A media asset outside the current workspace 404s.
     */
    public function media(Request $request, Video $video, MediaAsset $mediaAsset): StreamedResponse
    {
        $workspace = $this->currentWorkspace($request);

        abort_if($video->workspace_id !== $workspace->id, 404);
        abort_if($mediaAsset->workspace_id !== $workspace->id, 404);
        abort_if(! $video->attachments()->where('media_asset_id', $mediaAsset->id)->exists(), 404);

        return Storage::disk($mediaAsset->disk)->response(
            $mediaAsset->path,
            $mediaAsset->original_filename,
            [
                'Content-Type' => $mediaAsset->mime,
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
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

        return to_route('videos.show', $video);
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
        $videoCounts = Video::query()
            ->where('workspace_id', $workspace->id)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $counts = [
            'ideation' => Idea::query()
                ->where('workspace_id', $workspace->id)
                ->where('kind', 'video')
                ->where('status', 'open')
                ->count(),
        ];

        foreach (Video::STATUSES as $videoStatus) {
            $counts[$videoStatus] = (int) ($videoCounts[$videoStatus] ?? 0);
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
    private function presentSummary(Video $video): array
    {
        return [
            'type' => 'video',
            'id' => $video->id,
            'human_id' => $video->human_id,
            'number' => $video->number,
            'title' => $video->title,
            'status' => $video->status,
            'publish_state' => $video->publish_state,
            'language' => $video->language,
            'has_script' => PresenceFlags::bool(
                $video,
                'has_script',
                fn () => filled($video->script_markdown),
            ),
            'has_captions' => PresenceFlags::bool(
                $video,
                'has_captions',
                fn () => ! empty($video->captions),
            ),
            'has_deck' => PresenceFlags::bool(
                $video,
                'has_deck',
                fn () => ! empty($video->deck_manifest),
            ),
            'created_at' => $video->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDetail(Video $video): array
    {
        $parsed = ParseVideoScript::fromMarkdown(
            $video->script_markdown ?? '',
            $video->language ?? 'bn',
        );
        $workspace = Workspace::current();
        $postsyncerConfig = $workspace !== null
            ? PostsyncerConfig::fromWorkspace($workspace)
            : null;
        $publishOptions = is_array($video->publish_progress)
            && is_array($video->publish_progress['options'] ?? null)
            ? $video->publish_progress['options']
            : [];
        $needsConfirmAsk = $postsyncerConfig !== null
            && app(VideoPublishPlanner::class)->needsConfirmAsk(
                $video,
                $postsyncerConfig,
                $publishOptions,
            )
            && ! (bool) ($publishOptions['confirm_ask'] ?? false);

        return [
            'id' => $video->id,
            'human_id' => $video->human_id,
            'number' => $video->number,
            'title' => $video->title,
            'body' => $video->body,
            'script_markdown' => $video->script_markdown,
            'parsed' => $parsed,
            'captions' => NormalizeCaptions::forDashboard($video->captions),
            'has_deck' => ! empty($video->deck_manifest),
            'images' => $this->presentImages($video),
            'video_drive_url' => $video->video_drive_url,
            'cover_drive_url' => $video->cover_drive_url,
            'language' => $video->language,
            'slug' => $video->slug,
            'status' => $video->status,
            'publish_state' => $video->publish_state,
            'publish_error' => $video->publish_error,
            'publish_retryable' => $video->canRetryPublish(),
            'postsyncer' => $video->postsyncer,
            'postsyncer_ready' => $postsyncerConfig?->isReadyForPublish() ?? false,
            'video_publish_enabled' => $postsyncerConfig?->videoPublishEnabled() ?? false,
            'needs_confirm_ask' => $needsConfirmAsk,
            'idea_id' => $video->idea_id,
            'created_at' => $video->created_at?->toIso8601String(),
            'updated_at' => $video->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array{id: int, role: string|null, filename: string, url: string, mime: string|null}>
     */
    private function presentImages(Video $video): array
    {
        $images = [];

        foreach ($video->attachments as $attachment) {
            $media = $attachment->mediaAsset;

            if ($media === null) {
                continue;
            }

            $filename = $media->original_filename ?: basename($media->path);

            $images[] = [
                'id' => $attachment->id,
                'role' => $attachment->role,
                'filename' => $filename,
                'url' => route('videos.media', [$video, $media]),
                'mime' => $media->mime,
            ];
        }

        return $images;
    }
}
