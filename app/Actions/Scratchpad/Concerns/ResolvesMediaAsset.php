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
    /**
     * The only client-declared Content-Type values ever trusted for
     * storage/serving, for the reason explained in resolveMime() below. Not
     * a general-purpose audio mime list — deliberately just what this
     * app's own recorder (resources/js/components/scratchpad-voice-recorder.tsx)
     * and the equivalent common browser encoders actually produce.
     */
    private const ALLOWED_AUDIO_CLIENT_MIME_TYPES = [
        'audio/webm;codecs=opus',
        'audio/webm',
        'audio/mp4',
        'audio/ogg',
        'audio/mpeg',
        'audio/wav',
        'audio/x-wav',
        'audio/x-m4a',
    ];

    private function resolveMediaAsset(Workspace $workspace, ?User $uploadedBy, UploadedFile $file, string $kind): MediaAsset
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
            'mime' => $this->resolveMime($file, $kind),
            'bytes' => $file->getSize(),
            'checksum_sha256' => $checksum,
            'width' => $width,
            'height' => $height,
            // duration_ms is left null: probing audio/video duration needs an
            // ffmpeg/getID3-style dependency this task doesn't add. A later
            // phase can backfill it without a schema change.
            'original_filename' => $file->getClientOriginalName(),
            'uploaded_by_user_id' => $uploadedBy?->id,
        ]);
    }

    /**
     * The Content-Type stored on the MediaAsset and later replayed verbatim
     * as the HTTP response Content-Type when serving the file back
     * (ScratchpadController::media()) — so this can never be an arbitrary
     * client-supplied string, or an attacker could upload a file whose
     * bytes genuinely pass image/audio content-sniff validation while
     * separately declaring a spoofed Content-Type (e.g. "text/html") on
     * the multipart part, which validation never checks. A browser given
     * that combination back could interpret the stored bytes as HTML/script
     * (a polyglot-file stored-XSS), same-origin with the dashboard.
     *
     * Images: always the server-side, content-sniffed mime
     * (UploadedFile::getMimeType(), via finfo — not spoofable by the
     * client). Audio: PHP's content-sniffing genuinely cannot tell a
     * real audio-only WebM/fragmented-MP4 recording from a video one by
     * bytes alone (verified with real ffmpeg-encoded fixtures — see
     * StoreScratchpadVoiceRequest's validation rule docblock), so the
     * client-declared type is used instead, but ONLY after checking it
     * against ALLOWED_AUDIO_CLIENT_MIME_TYPES; anything else falls back to
     * application/octet-stream, which browsers download rather than render.
     */
    private function resolveMime(UploadedFile $file, string $kind): string
    {
        if ($kind !== 'audio') {
            return (string) $file->getMimeType();
        }

        $declared = (string) $file->getClientMimeType();

        return in_array($declared, self::ALLOWED_AUDIO_CLIENT_MIME_TYPES, true)
            ? $declared
            : 'application/octet-stream';
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
