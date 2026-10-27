<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Pexels\ImportPexelsPhotoAction;
use App\Actions\Pexels\SearchPexelsPhotosAction;
use App\Contracts\PexelsProvider;
use App\Data\Pexels\ImportPexelsPhotoData;
use App\Data\Pexels\SearchPexelsPhotosData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ImportPexelsPhotoRequest;
use App\Http\Requests\Api\V1\SearchPexelsPhotosRequest;
use App\Http\Resources\V1\MediaAssetResource;
use App\Models\Workspace;
use App\Support\Pexels\PexelsConfig;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class PexelsApiController extends Controller
{
    public function search(
        SearchPexelsPhotosRequest $request,
        SearchPexelsPhotosAction $action,
        PexelsProvider $provider,
    ): JsonResponse {
        if (! PexelsConfig::fromWorkspace($this->workspace())->isConfigured()) {
            return response()->json(['message' => 'Pexels is not configured for this workspace.'], 422);
        }

        try {
            $photos = $action->handle(SearchPexelsPhotosData::fromRequest($request), $provider);
        } catch (RuntimeException) {
            return response()->json(['message' => 'Pexels search failed. Check the workspace API key and try again.'], 502);
        }

        return response()->json(['data' => array_map(
            static fn ($photo): array => $photo->toSearchArray(),
            $photos,
        )]);
    }

    public function import(
        ImportPexelsPhotoRequest $request,
        ImportPexelsPhotoAction $action,
        PexelsProvider $provider,
    ): JsonResponse {
        $workspace = $this->workspace();
        if (! PexelsConfig::fromWorkspace($workspace)->isConfigured()) {
            return response()->json(['message' => 'Pexels is not configured for this workspace.'], 422);
        }

        try {
            $asset = $action->handle(
                $workspace,
                $request->user(),
                ImportPexelsPhotoData::fromRequest($request),
                $provider,
            );
        } catch (RuntimeException) {
            return response()->json(['message' => 'Pexels photo import failed. Check the photo ID and try again.'], 502);
        }

        $attribution = $asset->meta['pexels'];

        return response()->json([
            'data' => [
                'id' => $attribution['id'],
                'photographer' => $attribution['photographer'],
                'photographer_url' => $attribution['photographer_url'],
                'pexels_url' => $attribution['pexels_url'],
                'asset' => (new MediaAssetResource($asset))->resolve(),
            ],
        ], 201);
    }

    private function workspace(): Workspace
    {
        $workspace = Workspace::current();
        abort_if($workspace === null, 404, 'No current workspace.');

        return $workspace;
    }
}
