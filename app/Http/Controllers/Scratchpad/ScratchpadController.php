<?php

namespace App\Http\Controllers\Scratchpad;

use App\Actions\Scratchpad\CaptureScratchpadLinkAction;
use App\Actions\Scratchpad\CaptureScratchpadPhotoAction;
use App\Actions\Scratchpad\CaptureScratchpadVoiceAction;
use App\Actions\Scratchpad\CaptureTextNoteAction;
use App\Actions\Scratchpad\TriageScratchpadEntryAction;
use App\Data\Scratchpad\CaptureScratchpadLinkData;
use App\Data\Scratchpad\CaptureScratchpadPhotoData;
use App\Data\Scratchpad\CaptureScratchpadVoiceData;
use App\Data\Scratchpad\CaptureTextNoteData;
use App\Data\Scratchpad\TriageScratchpadEntryData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Scratchpad\StoreScratchpadLinkRequest;
use App\Http\Requests\Scratchpad\StoreScratchpadPhotoRequest;
use App\Http\Requests\Scratchpad\StoreScratchpadTextNoteRequest;
use App\Http\Requests\Scratchpad\StoreScratchpadVoiceRequest;
use App\Http\Requests\Scratchpad\TriageScratchpadEntryRequest;
use App\Models\Attachment;
use App\Models\MediaAsset;
use App\Models\ScratchpadEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ScratchpadController extends Controller
{
    /**
     * List the current workspace's scratchpad entries, newest first, with a
     * quick-capture text note form at the top.
     */
    public function index(Request $request): Response
    {
        $workspace = $this->currentWorkspace($request);

        $entries = ScratchpadEntry::query()
            ->where('workspace_id', $workspace->id)
            ->with('attachments.mediaAsset')
            ->orderByDesc('captured_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (ScratchpadEntry $entry) => $this->presentSummary($entry));

        return Inertia::render('scratchpad/index', [
            'entries' => $entries,
        ]);
    }

    /**
     * Capture a new text note.
     */
    public function store(StoreScratchpadTextNoteRequest $request, CaptureTextNoteAction $captureTextNoteAction): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);
        $user = $this->currentUser($request);

        $captureTextNoteAction->handle($workspace, $user, CaptureTextNoteData::fromRequest($request));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Note captured.'),
        ]);

        return to_route('dashboard.scratchpad.index');
    }

    /**
     * Capture a new photo. No OCR/AI reads the image; capture is pure
     * capture, same as the text note above.
     */
    public function storePhoto(StoreScratchpadPhotoRequest $request, CaptureScratchpadPhotoAction $captureScratchpadPhotoAction): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);
        $user = $this->currentUser($request);

        $captureScratchpadPhotoAction->handle($workspace, $user, CaptureScratchpadPhotoData::fromRequest($request));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Photo captured.'),
        ]);

        return to_route('dashboard.scratchpad.index');
    }

    /**
     * Capture a new voice memo. No transcription happens here, that's a
     * separate later phase; the entry simply has no transcript yet.
     */
    public function storeVoice(StoreScratchpadVoiceRequest $request, CaptureScratchpadVoiceAction $captureScratchpadVoiceAction): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);
        $user = $this->currentUser($request);

        $captureScratchpadVoiceAction->handle($workspace, $user, CaptureScratchpadVoiceData::fromRequest($request));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Voice note captured.'),
        ]);

        return to_route('dashboard.scratchpad.index');
    }

    /**
     * Capture a forwarded URL. Resolution (title/description via yt-dlp or
     * page metadata) happens afterward in a queued job, so the entry exists
     * immediately even if the URL is slow or never resolves.
     */
    public function storeLink(StoreScratchpadLinkRequest $request, CaptureScratchpadLinkAction $captureScratchpadLinkAction): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);
        $user = $this->currentUser($request);

        $captureScratchpadLinkAction->handle($workspace, $user, CaptureScratchpadLinkData::fromRequest($request));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Link captured.'),
        ]);

        return to_route('dashboard.scratchpad.index');
    }

    /**
     * Stream back a captured photo/voice file. The `scratchpad` disk is
     * private, so this is the only way to view one: it 404s for a media
     * asset outside the current workspace rather than exposing a public URL.
     */
    public function media(Request $request, MediaAsset $mediaAsset): StreamedResponse
    {
        $workspace = $this->currentWorkspace($request);

        abort_if($mediaAsset->workspace_id !== $workspace->id, 404);

        return Storage::disk($mediaAsset->disk)->response(
            $mediaAsset->path,
            $mediaAsset->original_filename,
            [
                'Content-Type' => $mediaAsset->mime,
                // Defense in depth on top of resolveMime()'s whitelist: even
                // if Content-Type were ever wrong, this stops a browser from
                // MIME-sniffing the body into something more dangerous.
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /**
     * Show a single entry. 404s if it's not in the current workspace so a
     * request can't view another workspace's capture by guessing an id.
     */
    public function show(Request $request, ScratchpadEntry $entry): Response
    {
        $workspace = $this->currentWorkspace($request);

        abort_if($entry->workspace_id !== $workspace->id, 404);

        $entry->load('attachments.mediaAsset');

        return Inertia::render('scratchpad/show', [
            'entry' => $this->presentDetail($entry),
        ]);
    }

    /**
     * Route a `status === 'new'` entry into a post idea, a video idea, or a
     * drop. Redirects back to the entry either way, whose `presentDetail`
     * then reflects the outcome (linked idea, or drop reason).
     */
    public function triage(TriageScratchpadEntryRequest $request, ScratchpadEntry $entry, TriageScratchpadEntryAction $triageScratchpadEntryAction): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);

        abort_if($entry->workspace_id !== $workspace->id, 404);

        $user = $this->currentUser($request);
        $data = TriageScratchpadEntryData::fromRequest($request);

        try {
            $triageScratchpadEntryAction->handle($entry, $user, $data);
        } catch (RuntimeException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return to_route('dashboard.scratchpad.show', $entry);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $data->target === 'drop' ? __('Entry dropped.') : __('Filed as an idea.'),
        ]);

        return to_route('dashboard.scratchpad.show', $entry);
    }

    private function currentUser(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        return $user;
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
    private function presentSummary(ScratchpadEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'public_id' => $entry->public_id,
            'kind' => $entry->kind,
            'status' => $entry->status,
            'title' => $entry->title,
            'preview' => $entry->body === null ? null : Str::limit($entry->body, 140),
            'captured_at' => $entry->captured_at->toIso8601String(),
            'language' => $entry->language,
            'attachments' => $this->presentAttachments($entry),
            'link' => $this->presentLink($entry),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDetail(ScratchpadEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'public_id' => $entry->public_id,
            'kind' => $entry->kind,
            'status' => $entry->status,
            'source' => $entry->source,
            'language' => $entry->language,
            'title' => $entry->title,
            'body' => $entry->body,
            'captured_at' => $entry->captured_at->toIso8601String(),
            'drop_reason' => $entry->drop_reason,
            'attachments' => $this->presentAttachments($entry),
            'link' => $this->presentLink($entry),
            'idea' => $this->presentTriagedIdea($entry),
        ];
    }

    /**
     * The entry's attached media (a photo's image, a voice memo's audio),
     * pointing at the private-disk-backed GET .../scratchpad/media/{id}
     * route rather than a public URL. Empty for a text entry.
     *
     * @return array<int, array<string, mixed>>
     */
    private function presentAttachments(ScratchpadEntry $entry): array
    {
        return $entry->attachments
            ->sortBy('position')
            ->values()
            ->map(fn (Attachment $attachment) => [
                'id' => $attachment->id,
                'role' => $attachment->role,
                'mime' => $attachment->mediaAsset->mime,
                'media_url' => route('dashboard.scratchpad.media', $attachment->media_asset_id),
            ])
            ->all();
    }

    /**
     * A link entry's original URL and how far ResolveScratchpadLinkAction
     * got resolving it, so the dashboard can render "resolved via: ..." as
     * honestly as the Telegram bot's own replies do. Null for every other
     * entry kind.
     *
     * @return array<string, mixed>|null
     */
    private function presentLink(ScratchpadEntry $entry): ?array
    {
        if ($entry->kind !== 'link') {
            return null;
        }

        return [
            'url' => $entry->meta['url'] ?? null,
            'resolved_via' => $entry->meta['resolved_via'] ?? null,
            'thumbnail_url' => $entry->meta['thumbnail_url'] ?? null,
        ];
    }

    /**
     * The idea this entry was triaged into, presented just enough to link
     * to it. Null for an untriaged or dropped entry.
     *
     * @return array<string, mixed>|null
     */
    private function presentTriagedIdea(ScratchpadEntry $entry): ?array
    {
        $idea = $entry->ideas()->first();

        if ($idea === null) {
            return null;
        }

        return [
            'id' => $idea->id,
            'human_id' => $idea->human_id,
            'kind' => $idea->kind,
            'title' => $idea->title,
        ];
    }
}
