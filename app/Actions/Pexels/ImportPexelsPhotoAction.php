<?php

namespace App\Actions\Pexels;

use App\Contracts\PexelsProvider;
use App\Data\Pexels\ImportPexelsPhotoData;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ImportPexelsPhotoAction
{
    public function handle(
        Workspace $workspace,
        ?User $user,
        ImportPexelsPhotoData $data,
        PexelsProvider $provider,
    ): MediaAsset {
        $photo = $provider->photo($data->id);
        $download = $provider->download($photo);
        $dimensions = @getimagesizefromstring($download['bytes']);
        if ($dimensions === false) {
            throw new RuntimeException('Pexels returned an unsupported image.');
        }

        $extension = match ($download['mime']) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw new RuntimeException('Pexels returned an unsupported image.'),
        };
        $checksum = hash('sha256', $download['bytes']);
        $attribution = [
            'id' => $photo->id,
            'photographer' => $photo->photographer,
            'photographer_url' => $photo->photographerUrl,
            'pexels_url' => $photo->pexelsUrl,
        ];

        // The same bytes can only exist once per workspace (unique checksum index), so a repeat import returns that asset.
        $existing = MediaAsset::query()
            ->where('workspace_id', $workspace->id)
            ->where('checksum_sha256', $checksum)
            ->first();
        if ($existing !== null) {
            if (! isset($existing->meta['pexels'])) {
                $existing->update(['meta' => [...($existing->meta ?? []), 'pexels' => $attribution]]);
            }

            return $existing;
        }

        $filename = 'source-pexels-'.$photo->id.'-'.Str::ulid().'.'.$extension;
        $path = (string) $workspace->id.'/'.$filename;
        Storage::disk('scratchpad')->put($path, $download['bytes']);

        try {
            return MediaAsset::create([
                'workspace_id' => $workspace->id,
                'kind' => 'image',
                'disk' => 'scratchpad',
                'path' => $path,
                'mime' => $download['mime'],
                'bytes' => strlen($download['bytes']),
                'checksum_sha256' => $checksum,
                'width' => $dimensions[0],
                'height' => $dimensions[1],
                'original_filename' => 'source-pexels-'.$photo->id.'.'.$extension,
                'uploaded_by_user_id' => $user?->id,
                'meta' => [
                    'source' => 'pexels',
                    'pexels' => $attribution,
                ],
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('scratchpad')->delete($path);
            throw $exception;
        }
    }
}
