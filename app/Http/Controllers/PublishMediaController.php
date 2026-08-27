<?php

namespace App\Http\Controllers;

use App\Models\MediaAsset;
use App\Models\Post;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Signed, unauthenticated media URLs for PostSyncer link-upload during publish.
 */
class PublishMediaController extends Controller
{
    public function post(Post $post, MediaAsset $mediaAsset): StreamedResponse
    {
        abort_if($mediaAsset->workspace_id !== $post->workspace_id, 404);

        $attached = $post->attachments()
            ->where('media_asset_id', $mediaAsset->id)
            ->exists();

        abort_unless($attached, 404);

        return Storage::disk($mediaAsset->disk)->response(
            $mediaAsset->path,
            $mediaAsset->original_filename,
            [
                'Content-Type' => $mediaAsset->mime,
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
