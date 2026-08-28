<?php

namespace App\Http\Controllers\Media;

use App\Actions\Media\DeleteLibraryMediaAction;
use App\Actions\Media\UpdateMediaAssetAction;
use App\Actions\Media\UploadLibraryMediaAction;
use App\Data\Media\UpdateMediaAssetData;
use App\Data\Media\UploadLibraryMediaData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Media\StoreLibraryMediaRequest;
use App\Http\Requests\Media\UpdateMediaAssetRequest;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Media\MediaLibraryTab;
use App\Support\Media\PresentMediaAsset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaLibraryController extends Controller
{
    public function images(Request $request, PresentMediaAsset $presentMediaAsset): Response
    {
        return $this->index($request, MediaLibraryTab::Images, $presentMediaAsset);
    }

    public function videos(Request $request, PresentMediaAsset $presentMediaAsset): Response
    {
        return $this->index($request, MediaLibraryTab::Videos, $presentMediaAsset);
    }

    public function gifs(Request $request, PresentMediaAsset $presentMediaAsset): Response
    {
        return $this->index($request, MediaLibraryTab::Gifs, $presentMediaAsset);
    }

    private function index(Request $request, MediaLibraryTab $libraryTab, PresentMediaAsset $presentMediaAsset): Response
    {
        $workspace = $this->currentWorkspace($request);

        $items = MediaAsset::query()
            ->where('workspace_id', $workspace->id)
            ->whereNotIn('kind', ['audio', 'document'])
            ->withCount('attachments')
            ->tap(fn ($query) => $libraryTab->applyTo($query))
            ->orderByDesc('created_at')
            ->paginate(24)
            ->withQueryString()
            ->through(fn (MediaAsset $asset) => $presentMediaAsset->summary($asset));

        return Inertia::render('media/index', [
            'tab' => $libraryTab->value,
            'tabLabel' => $libraryTab->label(),
            'items' => $items,
        ]);
    }

    public function store(
        StoreLibraryMediaRequest $request,
        UploadLibraryMediaAction $uploadLibraryMediaAction,
    ): RedirectResponse {
        $workspace = $this->currentWorkspace($request);
        $user = $this->currentUser($request);
        $data = UploadLibraryMediaData::fromRequest($request);

        $asset = $uploadLibraryMediaAction->handle($workspace, $user, $data);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Media uploaded.'),
        ]);

        return to_route('media.show', $asset);
    }

    public function show(Request $request, MediaAsset $mediaAsset, PresentMediaAsset $presentMediaAsset): Response
    {
        $workspace = $this->currentWorkspace($request);

        abort_if($mediaAsset->workspace_id !== $workspace->id, 404);

        $mediaAsset->load(['attachments.attachable']);

        return Inertia::render('media/show', [
            'asset' => $presentMediaAsset->detail($mediaAsset),
        ]);
    }

    public function update(
        UpdateMediaAssetRequest $request,
        MediaAsset $mediaAsset,
        UpdateMediaAssetAction $updateMediaAssetAction,
        PresentMediaAsset $presentMediaAsset,
    ): RedirectResponse {
        $workspace = $this->currentWorkspace($request);

        abort_if($mediaAsset->workspace_id !== $workspace->id, 404);

        $updateMediaAssetAction->handle($mediaAsset, UpdateMediaAssetData::fromRequest($request));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Media updated.'),
        ]);

        return to_route('media.show', $mediaAsset);
    }

    public function destroy(
        Request $request,
        MediaAsset $mediaAsset,
        DeleteLibraryMediaAction $deleteLibraryMediaAction,
    ): RedirectResponse {
        $workspace = $this->currentWorkspace($request);

        abort_if($mediaAsset->workspace_id !== $workspace->id, 404);

        try {
            $deleteLibraryMediaAction->handle($mediaAsset);
        } catch (RuntimeException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return to_route('media.show', $mediaAsset);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Media deleted.'),
        ]);

        return to_route('media.images');
    }

    public function file(Request $request, MediaAsset $mediaAsset): StreamedResponse
    {
        $workspace = $this->currentWorkspace($request);

        abort_if($mediaAsset->workspace_id !== $workspace->id, 404);

        return Storage::disk($mediaAsset->disk)->response(
            $mediaAsset->path,
            $mediaAsset->original_filename,
            [
                'Content-Type' => $mediaAsset->mime,
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    private function currentWorkspace(Request $request): Workspace
    {
        $workspace = Workspace::current();

        abort_if($workspace === null, 404, 'No current workspace.');

        return $workspace;
    }

    private function currentUser(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }
}
