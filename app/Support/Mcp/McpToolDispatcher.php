<?php

namespace App\Support\Mcp;

use App\Actions\Ideas\UpdateIdeaAction;
use App\Actions\Posts\UpdatePostAction;
use App\Actions\Postsyncer\EnqueuePostPublishAction;
use App\Actions\Postsyncer\EnqueueVideoPublishAction;
use App\Actions\Scratchpad\CaptureScratchpadLinkAction;
use App\Actions\Scratchpad\CaptureTextNoteAction;
use App\Actions\Scratchpad\DeleteScratchpadEntryAction;
use App\Actions\Scratchpad\TriageScratchpadEntryAction;
use App\Actions\Scratchpad\UpdateScratchpadEntryAction;
use App\Actions\Videos\UpdateVideoAction;
use App\Data\Ideas\UpdateIdeaData;
use App\Data\Posts\UpdatePostData;
use App\Data\Scratchpad\CaptureScratchpadLinkData;
use App\Data\Scratchpad\CaptureTextNoteData;
use App\Data\Scratchpad\TriageScratchpadEntryData;
use App\Data\Scratchpad\UpdateScratchpadEntryData;
use App\Data\Videos\UpdateVideoData;
use App\Http\Resources\V1\IdeaResource;
use App\Http\Resources\V1\PostResource;
use App\Http\Resources\V1\ScratchpadEntryResource;
use App\Http\Resources\V1\VideoResource;
use App\Models\Idea;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\ScratchpadEntry;
use App\Models\User;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Content\PresentationManifest;
use App\Support\CurrentApiToken;
use App\Support\GoogleDrive\GoogleDriveClient;
use App\Support\GoogleDrive\GoogleDriveLinkChecker;
use App\Support\Media\MediaLibraryTab;
use App\Support\Media\PresentMediaAsset;
use RuntimeException;

/**
 * Runs one MCP tools/call against the same Actions the JSON API uses.
 */
final class McpToolDispatcher
{
    public function __construct(
        private readonly CurrentApiToken $currentApiToken,
        private readonly CaptureTextNoteAction $captureTextNoteAction,
        private readonly CaptureScratchpadLinkAction $captureScratchpadLinkAction,
        private readonly UpdateScratchpadEntryAction $updateScratchpadEntryAction,
        private readonly DeleteScratchpadEntryAction $deleteScratchpadEntryAction,
        private readonly TriageScratchpadEntryAction $triageScratchpadEntryAction,
        private readonly UpdateIdeaAction $updateIdeaAction,
        private readonly UpdateVideoAction $updateVideoAction,
        private readonly UpdatePostAction $updatePostAction,
        private readonly EnqueuePostPublishAction $enqueuePostPublishAction,
        private readonly EnqueueVideoPublishAction $enqueueVideoPublishAction,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|list<mixed>
     */
    public function handle(string $name, array $arguments): array
    {
        $tool = McpToolCatalog::find($name);

        if ($tool === null) {
            throw new RuntimeException("Unknown tool: {$name}");
        }

        $token = $this->currentApiToken->get();

        if ($token === null || ! $token->hasAbility($tool['ability'])) {
            throw new RuntimeException("Token is missing the [{$tool['ability']}] ability.");
        }

        $workspace = Workspace::current();

        if ($workspace === null) {
            throw new RuntimeException('No current workspace.');
        }

        return match ($name) {
            'list_scratchpad' => $this->listScratchpad($arguments),
            'get_scratchpad' => $this->presentEntry($this->findEntry($this->stringArg($arguments, 'public_id'))),
            'capture_note' => $this->presentEntry($this->captureTextNoteAction->handle(
                $workspace,
                $this->actor(),
                CaptureTextNoteData::fromApi($this->stringArg($arguments, 'body')),
            )),
            'capture_link' => $this->presentEntry($this->captureScratchpadLinkAction->handle(
                $workspace,
                $this->actor(),
                CaptureScratchpadLinkData::fromApi($this->stringArg($arguments, 'url')),
            )),
            'list_media' => $this->listMedia($arguments),
            'update_scratchpad' => $this->updateScratchpad($arguments),
            'delete_scratchpad' => $this->deleteScratchpad($arguments),
            'triage_scratchpad' => $this->triageScratchpad($arguments),
            'list_ideas' => $this->listIdeas($arguments),
            'get_idea' => $this->presentIdea($this->findIdea($this->stringArg($arguments, 'human_id'))),
            'update_idea' => $this->updateIdea($arguments),
            'list_videos' => $this->listVideos($arguments),
            'get_video' => $this->presentVideo($this->findVideo($this->stringArg($arguments, 'human_id'))),
            'update_video' => $this->updateVideo($arguments),
            'check_drive_url' => $this->checkDriveUrl($arguments),
            'list_drive_files' => (new GoogleDriveClient($workspace))->listFiles(
                $this->optionalString($arguments, 'folder_id'),
                $this->optionalString($arguments, 'q'),
                $this->optionalString($arguments, 'page_token'),
            ),
            'make_drive_file_public' => [
                'file' => (new GoogleDriveClient($workspace))->makePublic(
                    $this->stringArg($arguments, 'file_id'),
                ),
            ],
            'publish_video' => $this->publishVideo($arguments),
            'list_posts' => $this->listPosts($arguments),
            'get_post' => $this->presentPost($this->findPost($this->stringArg($arguments, 'human_id'))),
            'update_post' => $this->updatePost($arguments),
            'publish_post' => $this->publishPost($arguments),
            default => throw new RuntimeException("Unknown tool: {$name}"),
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return list<array<string, mixed>>
     */
    private function listMedia(array $arguments): array
    {
        $tabValue = $this->optionalString($arguments, 'tab');
        $tab = $tabValue !== null ? MediaLibraryTab::from($tabValue) : null;
        $query = $this->optionalString($arguments, 'q');

        $builder = MediaAsset::query()
            ->where('workspace_id', Workspace::current()?->id)
            ->whereNotIn('kind', ['audio', 'document'])
            ->withCount('attachments')
            ->orderByDesc('created_at')
            ->limit(50);

        if ($tab !== null) {
            $tab->applyTo($builder);
        }

        if ($query !== null && $query !== '') {
            $like = '%'.$query.'%';
            $builder->where(function ($inner) use ($like) {
                $inner->where('title', 'ilike', $like)
                    ->orWhere('description', 'ilike', $like)
                    ->orWhere('original_filename', 'ilike', $like);
            });
        }

        $presenter = new PresentMediaAsset;

        return array_values($builder->get()
            ->map(fn (MediaAsset $asset) => $presenter->summary($asset))
            ->all());
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return list<array<string, mixed>>
     */
    private function listScratchpad(array $arguments): array
    {
        $status = $this->optionalString($arguments, 'status') ?? 'new';
        $kind = $this->optionalString($arguments, 'kind');

        $entries = ScratchpadEntry::query()
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($kind !== null, fn ($query) => $query->where('kind', $kind))
            ->with(['attachments.mediaAsset', 'transcriptions'])
            ->orderByDesc('captured_at')
            ->limit(50)
            ->get();

        $presented = [];

        foreach ($entries as $entry) {
            $presented[] = $this->presentEntry($entry);
        }

        return $presented;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function updateScratchpad(array $arguments): array
    {
        $entry = $this->findEntry($this->stringArg($arguments, 'public_id'));

        $data = new UpdateScratchpadEntryData(
            title: $this->optionalString($arguments, 'title'),
            body: $this->optionalString($arguments, 'body'),
            language: $this->optionalString($arguments, 'language'),
        );

        if ($data->changes() === []) {
            throw new RuntimeException('Send at least one of title, body, language.');
        }

        return $this->presentEntry($this->updateScratchpadEntryAction->handle($entry, $data));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function deleteScratchpad(array $arguments): array
    {
        $entry = $this->findEntry($this->stringArg($arguments, 'public_id'));
        $publicId = $entry->public_id;
        $this->deleteScratchpadEntryAction->handle($entry);

        return ['deleted' => true, 'public_id' => $publicId];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function triageScratchpad(array $arguments): array
    {
        $actor = $this->actor();

        if ($actor === null) {
            throw new RuntimeException('This token has no creator, so it cannot triage.');
        }

        $entry = $this->findEntry($this->stringArg($arguments, 'public_id'));
        $score = $arguments['score'] ?? null;

        $data = new TriageScratchpadEntryData(
            target: $this->stringArg($arguments, 'target'),
            title: $this->optionalString($arguments, 'title'),
            score: is_numeric($score) ? (int) $score : null,
            trend: $this->optionalString($arguments, 'trend'),
            rationale: $this->optionalString($arguments, 'rationale'),
            dropReason: $this->optionalString($arguments, 'drop_reason'),
        );

        return $this->presentEntry($this->triageScratchpadEntryAction->handle($entry, $actor, $data));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return list<array<string, mixed>>
     */
    private function listIdeas(array $arguments): array
    {
        $kind = $this->optionalString($arguments, 'kind');
        $status = $this->optionalString($arguments, 'status');

        $ideas = Idea::query()
            ->when($kind !== null, fn ($query) => $query->where('kind', $kind))
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $presented = [];

        foreach ($ideas as $idea) {
            $presented[] = $this->presentIdea($idea);
        }

        return $presented;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function updateIdea(array $arguments): array
    {
        $idea = $this->findIdea($this->stringArg($arguments, 'human_id'));
        $score = $arguments['score'] ?? null;

        $data = new UpdateIdeaData(
            title: $this->stringArg($arguments, 'title'),
            score: is_numeric($score) ? (int) $score : $idea->score,
            trend: $this->optionalString($arguments, 'trend') ?? $idea->trend,
            rationale: $this->optionalString($arguments, 'rationale') ?? $idea->rationale,
            body: $this->optionalString($arguments, 'body') ?? $idea->body,
        );

        return $this->presentIdea($this->updateIdeaAction->handle($idea, $data));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return list<array<string, mixed>>
     */
    private function listVideos(array $arguments): array
    {
        $status = $this->optionalString($arguments, 'status');
        $language = $this->optionalString($arguments, 'language');

        $videos = Video::query()
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($language !== null, fn ($query) => $query->where('language', $language))
            ->orderByDesc('number')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $presented = [];

        foreach ($videos as $video) {
            $presented[] = $this->presentVideo($video);
        }

        return $presented;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function updateVideo(array $arguments): array
    {
        $video = $this->findVideo($this->stringArg($arguments, 'human_id'));
        $payload = $this->optionalPayload($arguments, [
            'title',
            'language',
            'slug',
            'body',
            'script_markdown',
            'status',
            'video_drive_url',
            'cover_drive_url',
        ]);

        if (array_key_exists('deck_manifest', $arguments)) {
            $manifest = $arguments['deck_manifest'];

            if ($manifest !== null && ! is_array($manifest)) {
                throw new RuntimeException('deck_manifest must be an object or null.');
            }
            if (is_array($manifest) && ! PresentationManifest::isUsable($manifest)) {
                throw new RuntimeException('deck_manifest must contain a registered, renderable presentation deck.');
            }

            $payload['deck_manifest'] = $manifest;
        }

        if ($payload === []) {
            throw new RuntimeException('Send at least one of title, language, slug, body, script_markdown, deck_manifest, status, video_drive_url, cover_drive_url.');
        }

        $this->assertAllowedStatus($payload, Video::STATUSES, 'video');
        $this->assertAccessibleDriveUrl($payload['video_drive_url'] ?? null, 'video_drive_url');
        $this->assertAccessibleDriveUrl($payload['cover_drive_url'] ?? null, 'cover_drive_url');

        $this->updateVideoAction->handle($video, UpdateVideoData::fromApiPayload($payload, $video));

        return $this->presentVideo($video->fresh() ?? $video);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function publishVideo(array $arguments): array
    {
        $video = $this->findVideo($this->stringArg($arguments, 'human_id'));
        $workspace = Workspace::current();

        if ($workspace === null) {
            throw new RuntimeException('No current workspace.');
        }

        $options = [];
        $when = $this->optionalString($arguments, 'when');

        if ($when !== null) {
            $options['when'] = $when;
        }

        if (array_key_exists('platforms', $arguments)) {
            $platforms = $arguments['platforms'];

            if ($platforms !== null && ! is_array($platforms)) {
                throw new RuntimeException('platforms must be an array.');
            }

            if (is_array($platforms)) {
                $options['platforms'] = array_values(array_map(
                    fn (mixed $platform): string => is_string($platform) ? $platform : '',
                    $platforms,
                ));
            }
        }

        if (array_key_exists('confirm_ask', $arguments)) {
            $options['confirm_ask'] = (bool) $arguments['confirm_ask'];
        }

        $this->enqueueVideoPublishAction->handle($video, $workspace, $options);

        return $this->presentVideo($video->fresh() ?? $video);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return list<array<string, mixed>>
     */
    private function listPosts(array $arguments): array
    {
        $status = $this->optionalString($arguments, 'status');
        $language = $this->optionalString($arguments, 'language');

        $posts = Post::query()
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($language !== null, fn ($query) => $query->where('language', $language))
            ->orderByDesc('number')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $presented = [];

        foreach ($posts as $post) {
            $presented[] = $this->presentPost($post);
        }

        return $presented;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function updatePost(array $arguments): array
    {
        $post = $this->findPost($this->stringArg($arguments, 'human_id'));
        $payload = $this->optionalPayload($arguments, ['title', 'body', 'status']);

        if (array_key_exists('captions', $arguments)) {
            $captions = $arguments['captions'];

            if ($captions !== null && ! is_array($captions)) {
                throw new RuntimeException('captions must be an object.');
            }

            $payload['captions'] = $captions;
        }

        if (array_key_exists('platforms', $arguments)) {
            $platforms = $arguments['platforms'];

            if ($platforms !== null && ! is_array($platforms)) {
                throw new RuntimeException('platforms must be an array.');
            }

            $payload['platforms'] = $platforms;
        }

        if (array_key_exists('image_drive_urls', $arguments)) {
            $urls = $arguments['image_drive_urls'];

            if ($urls !== null && ! is_array($urls)) {
                throw new RuntimeException('image_drive_urls must be an array.');
            }

            $payload['image_drive_urls'] = $urls;

            foreach (is_array($urls) ? $urls : [] as $url) {
                $this->assertAccessibleDriveUrl($url, 'image_drive_urls');
            }
        }

        if ($payload === []) {
            throw new RuntimeException('Send at least one of title, body, captions, platforms, status, image_drive_urls.');
        }

        $this->assertAllowedStatus($payload, Post::STATUSES, 'post');

        $this->updatePostAction->handle($post, UpdatePostData::fromApiPayload($payload, $post));

        return $this->presentPost($post->fresh(['attachments.mediaAsset']) ?? $post);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function publishPost(array $arguments): array
    {
        $post = $this->findPost($this->stringArg($arguments, 'human_id'));
        $workspace = Workspace::current();

        if ($workspace === null) {
            throw new RuntimeException('No current workspace.');
        }

        $options = [];
        $when = $this->optionalString($arguments, 'when');

        if ($when !== null) {
            $options['when'] = $when;
        }

        if (array_key_exists('platforms', $arguments)) {
            $platforms = $arguments['platforms'];

            if ($platforms !== null && ! is_array($platforms)) {
                throw new RuntimeException('platforms must be an array.');
            }

            if (is_array($platforms)) {
                $options['platforms'] = array_values(array_map(
                    fn (mixed $platform): string => is_string($platform) ? $platform : '',
                    $platforms,
                ));
            }
        }

        if (array_key_exists('confirm_ask', $arguments)) {
            $options['confirm_ask'] = (bool) $arguments['confirm_ask'];
        }

        $this->enqueuePostPublishAction->handle($post, $workspace, $options);

        return $this->presentPost($post->fresh(['attachments.mediaAsset']) ?? $post);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentEntry(ScratchpadEntry $entry): array
    {
        $entry->loadMissing(['attachments.mediaAsset', 'transcriptions']);

        /** @var array<string, mixed> $payload */
        $payload = (new ScratchpadEntryResource($entry))->resolve();

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentIdea(Idea $idea): array
    {
        /** @var array<string, mixed> $payload */
        $payload = (new IdeaResource($idea))->resolve();

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentVideo(Video $video): array
    {
        /** @var array<string, mixed> $payload */
        $payload = (new VideoResource($video))->resolve();

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPost(Post $post): array
    {
        $post->loadMissing(['attachments.mediaAsset']);

        /** @var array<string, mixed> $payload */
        $payload = (new PostResource($post))->resolve();

        return $payload;
    }

    private function findEntry(string $publicId): ScratchpadEntry
    {
        $entry = ScratchpadEntry::query()->where('public_id', $publicId)->first();

        if ($entry === null) {
            throw new RuntimeException('Scratch pad entry not found.');
        }

        return $entry;
    }

    private function findIdea(string $humanId): Idea
    {
        $idea = Idea::query()->where('human_id', $humanId)->first();

        if ($idea === null) {
            throw new RuntimeException('Idea not found.');
        }

        return $idea;
    }

    private function findVideo(string $humanId): Video
    {
        $video = Video::query()->where('human_id', $humanId)->first();

        if ($video === null) {
            throw new RuntimeException('Video not found.');
        }

        return $video;
    }

    private function findPost(string $humanId): Post
    {
        $post = Post::query()->where('human_id', $humanId)->first();

        if ($post === null) {
            throw new RuntimeException('Post not found.');
        }

        return $post;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function optionalPayload(array $arguments, array $keys): array
    {
        $payload = [];

        foreach ($keys as $key) {
            if (! array_key_exists($key, $arguments)) {
                continue;
            }

            $value = $arguments[$key];

            if ($value !== null && ! is_string($value)) {
                throw new RuntimeException("{$key} must be a string.");
            }

            $payload[$key] = $value;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function checkDriveUrl(array $arguments): array
    {
        return app(GoogleDriveLinkChecker::class)
            ->check($this->stringArg($arguments, 'url'))
            ->toArray();
    }

    private function assertAccessibleDriveUrl(mixed $url, string $field): void
    {
        if (! is_string($url) || trim($url) === '') {
            return;
        }

        $result = app(GoogleDriveLinkChecker::class)->check($url);

        if (! $result->ok) {
            throw new RuntimeException("{$field}: {$result->message}");
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $allowed
     */
    private function assertAllowedStatus(array $payload, array $allowed, string $kind): void
    {
        if (! array_key_exists('status', $payload)) {
            return;
        }

        $status = $payload['status'];

        if (! is_string($status) || ! in_array($status, $allowed, true)) {
            $shown = is_string($status) ? $status : '';

            throw new RuntimeException("Invalid {$kind} status [{$shown}].");
        }
    }

    private function actor(): ?User
    {
        return $this->currentApiToken->get()?->createdBy;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function stringArg(array $arguments, string $key): string
    {
        $value = $arguments[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("Missing required argument: {$key}");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function optionalString(array $arguments, string $key): ?string
    {
        $value = $arguments[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
