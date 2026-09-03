<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Scratchpad\CaptureScratchpadLinkAction;
use App\Actions\Scratchpad\CaptureScratchpadPhotoAction;
use App\Actions\Scratchpad\CaptureScratchpadVoiceAction;
use App\Actions\Scratchpad\CaptureTextNoteAction;
use App\Actions\Scratchpad\DeleteScratchpadEntryAction;
use App\Actions\Scratchpad\TriageScratchpadEntryAction;
use App\Actions\Scratchpad\UpdateScratchpadEntryAction;
use App\Data\Scratchpad\CaptureScratchpadLinkData;
use App\Data\Scratchpad\CaptureScratchpadPhotoData;
use App\Data\Scratchpad\CaptureScratchpadVoiceData;
use App\Data\Scratchpad\CaptureTextNoteData;
use App\Data\Scratchpad\TriageScratchpadEntryData;
use App\Data\Scratchpad\UpdateScratchpadEntryData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateScratchpadEntryRequest;
use App\Http\Requests\Scratchpad\StoreScratchpadLinkRequest;
use App\Http\Requests\Scratchpad\StoreScratchpadPhotoRequest;
use App\Http\Requests\Scratchpad\StoreScratchpadTextNoteRequest;
use App\Http\Requests\Scratchpad\StoreScratchpadVoiceRequest;
use App\Http\Requests\Scratchpad\TriageScratchpadEntryRequest;
use App\Http\Resources\V1\ScratchpadEntryResource;
use App\Models\MediaAsset;
use App\Models\ScratchpadEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The JSON surface over the exact same Scratch Pad Actions the dashboard
 * and Telegram bot use. Thin on purpose: Form Request → Data → one Action
 * → Resource. Every route is guarded by auth.workspace-token with the
 * ability it needs (scratchpad:read / scratchpad:write), and every entry
 * lookup re-checks workspace membership even though BelongsToWorkspace's
 * global scope already filters — same belt-and-braces as the dashboard.
 */
class ScratchpadApiController extends Controller
{
    /**
     * List entries, newest first. Defaults to status=new — "what's left to
     * work through" — pass ?status=all for everything.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->currentWorkspace();

        $status = $request->string('status', 'new')->toString();
        $kind = $request->string('kind')->toString() ?: null;

        $entries = ScratchpadEntry::query()
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($kind !== null, fn ($query) => $query->where('kind', $kind))
            ->with(['attachments.mediaAsset', 'transcriptions'])
            ->orderByDesc('captured_at')
            ->cursorPaginate(50)
            ->withQueryString();

        return ScratchpadEntryResource::collection($entries);
    }

    public function show(string $publicId): ScratchpadEntryResource
    {
        $workspace = $this->currentWorkspace();

        $entry = ScratchpadEntry::query()
            ->with(['attachments.mediaAsset', 'transcriptions'])
            ->where('public_id', $publicId)
            ->firstOrFail();

        abort_if($entry->workspace_id !== $workspace->id, 404);

        return new ScratchpadEntryResource($entry);
    }

    public function captureText(StoreScratchpadTextNoteRequest $request, CaptureTextNoteAction $action): JsonResponse
    {
        $entry = $action->handle(
            $this->currentWorkspace(),
            $request->user(),
            CaptureTextNoteData::fromApi($request->string('body')->toString()),
        );

        return (new ScratchpadEntryResource($entry->load(['attachments.mediaAsset', 'transcriptions'])))
            ->response()
            ->setStatusCode(201);
    }

    public function captureLink(StoreScratchpadLinkRequest $request, CaptureScratchpadLinkAction $action): JsonResponse
    {
        $entry = $action->handle(
            $this->currentWorkspace(),
            $request->user(),
            CaptureScratchpadLinkData::fromApi($request->string('url')->toString()),
        );

        return (new ScratchpadEntryResource($entry->load(['attachments.mediaAsset', 'transcriptions'])))
            ->response()
            ->setStatusCode(201);
    }

    public function capturePhoto(StoreScratchpadPhotoRequest $request, CaptureScratchpadPhotoAction $action): JsonResponse
    {
        /** @var UploadedFile $file */
        $file = $request->file('photo');

        $entry = $action->handle(
            $this->currentWorkspace(),
            $request->user(),
            CaptureScratchpadPhotoData::fromApi($file, $request->string('caption')->toString() ?: null),
        );

        return (new ScratchpadEntryResource($entry->load(['attachments.mediaAsset', 'transcriptions'])))
            ->response()
            ->setStatusCode(201);
    }

    public function captureVoice(StoreScratchpadVoiceRequest $request, CaptureScratchpadVoiceAction $action): JsonResponse
    {
        /** @var UploadedFile $file */
        $file = $request->file('audio');

        $entry = $action->handle(
            $this->currentWorkspace(),
            $request->user(),
            CaptureScratchpadVoiceData::fromApi($file, $request->string('language')->toString() ?: null),
        );

        return (new ScratchpadEntryResource($entry->load(['attachments.mediaAsset', 'transcriptions'])))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateScratchpadEntryRequest $request, string $publicId, UpdateScratchpadEntryAction $action): ScratchpadEntryResource
    {
        $entry = $this->resolveEntry($publicId);

        try {
            $action->handle($entry, UpdateScratchpadEntryData::fromRequest($request));
        } catch (RuntimeException $e) {
            abort(409, $e->getMessage());
        }

        return new ScratchpadEntryResource($entry->fresh(['attachments.mediaAsset', 'transcriptions']));
    }

    public function destroy(Request $request, string $publicId, DeleteScratchpadEntryAction $action): JsonResponse
    {
        $entry = $this->resolveEntry($publicId);

        try {
            $action->handle($entry);
        } catch (RuntimeException $e) {
            abort(409, $e->getMessage());
        }

        return response()->json(['deleted' => true]);
    }

    public function triage(TriageScratchpadEntryRequest $request, string $publicId, TriageScratchpadEntryAction $action): ScratchpadEntryResource
    {
        $entry = $this->resolveEntry($publicId);

        $user = $request->user();

        abort_if(! $user instanceof User, 403, 'This token has no owning user to triage as.');

        try {
            $action->handle($entry, $user, TriageScratchpadEntryData::fromRequest($request));
        } catch (RuntimeException $e) {
            abort(409, $e->getMessage());
        }

        return new ScratchpadEntryResource($entry->fresh(['attachments.mediaAsset', 'transcriptions']));
    }

    /**
     * Stream a captured photo/audio file. The scratchpad disk is private,
     * and this endpoint is token-guarded; a media asset from another
     * workspace 404s rather than streaming.
     */
    public function media(Request $request, string $publicId, MediaAsset $mediaAsset): StreamedResponse
    {
        $workspace = $this->currentWorkspace();

        $entry = $this->resolveEntry($publicId);
        abort_if($mediaAsset->workspace_id !== $workspace->id, 404);
        abort_if(! $entry->attachments()->where('media_asset_id', $mediaAsset->id)->exists(), 404);

        return Storage::disk($mediaAsset->disk)->response(
            $mediaAsset->path,
            $mediaAsset->original_filename,
            [
                'Content-Type' => $mediaAsset->mime,
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    private function resolveEntry(string $publicId): ScratchpadEntry
    {
        $workspace = $this->currentWorkspace();

        $entry = ScratchpadEntry::query()->where('public_id', $publicId)->firstOrFail();

        abort_if($entry->workspace_id !== $workspace->id, 404);

        return $entry;
    }

    private function currentWorkspace(): Workspace
    {
        $workspace = Workspace::current();

        abort_if($workspace === null, 404, 'No current workspace.');

        return $workspace;
    }
}
