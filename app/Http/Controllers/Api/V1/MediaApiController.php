<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\MediaAssetResource;
use App\Models\MediaAsset;
use App\Models\Workspace;
use App\Support\Media\MediaLibraryTab;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class MediaApiController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $workspace = Workspace::current();

        abort_if($workspace === null, 404, 'No current workspace.');

        $validated = $request->validate([
            'tab' => ['nullable', 'string', Rule::enum(MediaLibraryTab::class)],
            'q' => ['nullable', 'string', 'max:200'],
        ]);

        $tab = isset($validated['tab'])
            ? MediaLibraryTab::from($validated['tab'])
            : null;

        $query = MediaAsset::query()
            ->where('workspace_id', $workspace->id)
            ->whereNotIn('kind', ['audio', 'document'])
            ->withCount('attachments')
            ->orderByDesc('created_at');

        if ($tab !== null) {
            $tab->applyTo($query);
        }

        if (! empty($validated['q'])) {
            $like = '%'.$validated['q'].'%';
            $query->where(function ($inner) use ($like) {
                $inner->where('title', 'ilike', $like)
                    ->orWhere('description', 'ilike', $like)
                    ->orWhere('original_filename', 'ilike', $like);
            });
        }

        return MediaAssetResource::collection(
            $query->paginate(min((int) $request->integer('per_page', 50), 100)),
        );
    }
}
