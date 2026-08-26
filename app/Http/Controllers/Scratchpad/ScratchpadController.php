<?php

namespace App\Http\Controllers\Scratchpad;

use App\Actions\Scratchpad\CaptureScratchpadLinkAction;
use App\Actions\Scratchpad\CaptureScratchpadPhotoAction;
use App\Actions\Scratchpad\CaptureScratchpadVoiceAction;
use App\Actions\Scratchpad\CaptureTextNoteAction;
use App\Actions\Scratchpad\DeleteScratchpadEntryAction;
use App\Actions\Scratchpad\SuggestIdeaFramingAction;
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
use App\Http\Requests\Scratchpad\SuggestIdeaFramingRequest;
use App\Http\Requests\Scratchpad\TriageScratchpadEntryRequest;
use App\Models\Attachment;
use App\Models\MediaAsset;
use App\Models\ScratchpadEntry;
use App\Models\Transcription;
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
            ->with(['attachments.mediaAsset', 'transcriptions'])
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

        return to_route('scratchpad.index');
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

        return to_route('scratchpad.index');
    }

    /**
     * Capture a new voice memo. Transcription happens afterward in a queued
     * job (see CaptureScratchpadVoiceAction), so the entry exists
     * immediately with its transcript still pending.
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

        return to_route('scratchpad.index');
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

        return to_route('scratchpad.index');
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

        $entry->load(['attachments.mediaAsset', 'transcriptions']);

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

            return to_route('scratchpad.show', $entry);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $data->target === 'drop' ? __('Entry dropped.') : __('Filed as an idea.'),
        ]);

        return to_route('scratchpad.show', $entry);
    }

    /**
     * Ask the AI for a title/score/trend/rationale suggestion for filing
     * this entry as a post or video idea. Purely advisory: nothing here
     * persists, the entry and its idea state are unchanged, this only
     * enriches the current page with a `suggestion` prop for the triage
     * panel to prefill from.
     */
    public function suggestTriage(SuggestIdeaFramingRequest $request, ScratchpadEntry $entry, SuggestIdeaFramingAction $suggestIdeaFramingAction): Response
    {
        $workspace = $this->currentWorkspace($request);

        abort_if($entry->workspace_id !== $workspace->id, 404);

        $entry->load('transcriptions');
        $target = $request->string('target')->toString();
        $kind = Str::before($target, '_idea');

        $suggestion = $suggestIdeaFramingAction->handle($entry, $kind);

        $entry->load(['attachments.mediaAsset']);

        return Inertia::render('scratchpad/show', [
            'entry' => $this->presentDetail($entry),
            'suggestion' => [
                'target' => $target,
                'successful' => $suggestion->successful,
                'title' => $suggestion->title,
                'score' => $suggestion->score,
                'trend' => $suggestion->trend,
                'rationale' => $suggestion->rationale,
                'error' => $suggestion->error,
            ],
        ]);
    }

    /**
     * Hard-delete an entry. 404s if it's not in the current workspace, same
     * boundary as every other single-entry action here; refuses (via a
     * flashed error, back to the entry) if it's already been triaged into
     * an idea, since DeleteScratchpadEntryAction won't sever that link.
     */
    public function destroy(Request $request, ScratchpadEntry $entry, DeleteScratchpadEntryAction $deleteScratchpadEntryAction): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);

        abort_if($entry->workspace_id !== $workspace->id, 404);

        try {
            $deleteScratchpadEntryAction->handle($entry);
        } catch (RuntimeException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return to_route('scratchpad.show', $entry);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Note deleted.'),
        ]);

        return to_route('scratchpad.index');
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
        $preview = $entry->body ?? $this->presentTranscription($entry)['text'] ?? null;

        return [
            'id' => $entry->id,
            'public_id' => $entry->public_id,
            'kind' => $entry->kind,
            'status' => $entry->status,
            'title' => $entry->title,
            'preview' => $preview === null ? null : Str::limit($preview, 140),
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
            'transcription' => $this->presentTranscription($entry),
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
                'media_url' => route('scratchpad.media', $attachment->media_asset_id),
            ])
            ->all();
    }

    /**
     * A link entry's original URL and how far ResolveScratchpadLinkAction
     * got resolving it, so the dashboard can render "resolved via: ..." as
     * honestly as the Telegram bot's own replies do. `summarized` flags
     * whether the entry's body is SummarizeCaptureAction's AI-written
     * rewrite rather than the raw scraped description. Null for every
     * other entry kind.
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
            'summarized' => isset($entry->meta['summarized_at']),
        ];
    }

    /**
     * A voice entry's transcription, whatever stage it's at: still
     * pending/processing, done with text, or failed with an honest reason.
     * Null for every other entry kind, matching presentLink()'s shape.
     *
     * @return array<string, mixed>|null
     */
    private function presentTranscription(ScratchpadEntry $entry): ?array
    {
        $transcription = $entry->transcriptions->first();

        if ($transcription === null) {
            return null;
        }

        return [
            'status' => $transcription->status,
            'text' => $transcription->text,
            'language' => $transcription->language,
            'error_message' => $transcription->error_message,
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
