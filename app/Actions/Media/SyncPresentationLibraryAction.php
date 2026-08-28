<?php

namespace App\Actions\Media;

use App\Models\MediaAsset;
use App\Models\Workspace;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SyncPresentationLibraryAction
{
    /**
     * Upsert workspace MediaAsset rows for every SVG in resources/presentation-library/assets.
     */
    public function handle(Workspace $workspace): int
    {
        $libraryPath = resource_path('presentation-library/assets');

        if (! is_dir($libraryPath)) {
            return 0;
        }

        $synced = 0;

        foreach (glob($libraryPath.'/*.svg') ?: [] as $filePath) {
            if ($this->syncFile($workspace, $filePath)) {
                $synced++;
            }
        }

        return $synced;
    }

    private function syncFile(Workspace $workspace, string $filePath): bool
    {
        $assetKey = pathinfo($filePath, PATHINFO_FILENAME);
        $contents = file_get_contents($filePath);

        if ($contents === false || $assetKey === '') {
            return false;
        }

        $checksum = hash('sha256', $contents);
        $relativePath = $workspace->id.'/presentation-library/'.$assetKey.'.svg';

        $existing = MediaAsset::query()
            ->where('workspace_id', $workspace->id)
            ->where('meta->source', 'presentation_library')
            ->where('meta->asset_key', $assetKey)
            ->first();

        if ($existing !== null && $existing->checksum_sha256 === $checksum) {
            return false;
        }

        Storage::disk('scratchpad')->put($relativePath, $contents);

        $attributes = [
            'kind' => 'image',
            'disk' => 'scratchpad',
            'path' => $relativePath,
            'mime' => 'image/svg+xml',
            'bytes' => strlen($contents),
            'checksum_sha256' => $checksum,
            'width' => null,
            'height' => null,
            'original_filename' => $assetKey.'.svg',
            'title' => Str::headline(str_replace('-', ' ', $assetKey)),
            'description' => "Shared presentation deck SVG. Use in decks as PA('{$assetKey}').",
            'uploaded_by_user_id' => null,
            'meta' => [
                'source' => 'presentation_library',
                'asset_key' => $assetKey,
            ],
        ];

        if ($existing !== null) {
            $existing->update($attributes);

            return true;
        }

        MediaAsset::create([
            'workspace_id' => $workspace->id,
            ...$attributes,
        ]);

        return true;
    }
}
