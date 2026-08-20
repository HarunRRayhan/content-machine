<?php

namespace App\Actions\Scratchpad\Concerns;

use App\Models\MediaAsset;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Shared by CaptureScratchpadPhotoAction and CaptureScratchpadVoiceAction:
 * store an uploaded file on the `scratchpad` disk and create its MediaAsset
 * row, or reuse an existing MediaAsset already in the same workspace when
 * the upload's sha256 checksum matches one (media_assets' partial unique
 * index on (workspace_id, checksum_sha256) is what this dedupes against,
 * so the same bytes are never stored twice for one workspace).
 */
trait ResolvesMediaAsset
{
    private function resolveMediaAsset(Workspace $workspace, User $uploadedBy, UploadedFile $file, string $kind): MediaAsset
    {
        $checksum = hash_file('sha256', $file->getRealPath());

        $existing = MediaAsset::query()
            ->where('workspace_id', $workspace->id)
            ->where('checksum_sha256', $checksum)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $storedPath = $this->storeFile($workspace, $file);

        [$width, $height] = $kind === 'image'
            ? $this->detectImageDimensions($storedPath)
            : [null, null];

        return MediaAsset::create([
            'workspace_id' => $workspace->id,
            'kind' => $kind,
            'disk' => 'scratchpad',
            'path' => $storedPath,
            // getClientMimeType() (browser-declared), not getMimeType()
            // (content-sniffed): verified with real ffmpeg-encoded
            // audio-only fixtures that PHP's finfo/Symfony MimeTypes
            // content-sniffing cannot tell an audio-only WebM/fragmented-MP4
            // container from a video one, so a genuine browser voice
            // recording's real bytes sniff as 'video/webm' or 'video/mp4',
            // not 'audio/*'. Storing that would break this app's own
            // `mime.startsWith('audio/')` UI check and mislabel the file.
            // Content-sniffing is still what StoreScratchpadVoiceRequest's
            // `mimetypes:` rule validates against (security-relevant, can't
            // be spoofed by the client); the client-declared type is only
            // trusted afterward, for display, once that check has passed.
            'mime' => $file->getClientMimeType(),
            'bytes' => $file->getSize(),
            'checksum_sha256' => $checksum,
            'width' => $width,
            'height' => $height,
            // duration_ms is left null: probing audio/video duration needs an
            // ffmpeg/getID3-style dependency this task doesn't add. A later
            // phase can backfill it without a schema change.
            'original_filename' => $file->getClientOriginalName(),
            'uploaded_by_user_id' => $uploadedBy->id,
        ]);
    }

    private function storeFile(Workspace $workspace, UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension() ?: ($file->extension() ?: 'bin');
        $filename = Str::ulid().'.'.$extension;

        $storedPath = Storage::disk('scratchpad')->putFileAs((string) $workspace->id, $file, $filename);

        // The 'scratchpad' disk config has 'throw' => true, so a failed
        // putFileAs() above throws rather than returning false; this is just
        // narrowing the type for static analysis.
        return (string) $storedPath;
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function detectImageDimensions(string $storedPath): array
    {
        try {
            $size = @getimagesize(Storage::disk('scratchpad')->path($storedPath));
        } catch (Throwable) {
            return [null, null];
        }

        if ($size === false) {
            return [null, null];
        }

        return [$size[0], $size[1]];
    }
}
