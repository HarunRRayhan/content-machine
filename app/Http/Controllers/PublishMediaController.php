<?php

namespace App\Http\Controllers;

use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\SocialreinsGroup;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Signed, unauthenticated media URLs for PostSyncer link-upload and SocialReins publish.
 */
class PublishMediaController extends Controller
{
    /** How long after a SocialReins group's scheduled time an expired link still works. */
    private const SOCIALREINS_GRACE_DAYS = 7;

    public function post(Request $request, Post $post, MediaAsset $mediaAsset): StreamedResponse
    {
        abort_unless(URL::hasCorrectSignature($request), 403, 'Invalid signature.');
        // SocialReins fetches media at publish time, which can be after the link's expiry.
        abort_unless(
            URL::signatureHasNotExpired($request) || $this->awaitsSocialreinsPublish($post),
            403,
            'Invalid signature.',
        );

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

    /** A live (not rejected) SocialReins group of this post is scheduled within the grace window. */
    private function awaitsSocialreinsPublish(Post $post): bool
    {
        if ($post->status === 'archived') {
            return false;
        }

        $rows = SocialreinsGroup::query()
            ->where('subject_type', 'post')
            ->where('subject_id', $post->id)
            ->where('state', '!=', SocialreinsGroup::REJECTED)
            ->get(['request', 'results']);

        foreach ($rows as $row) {
            // A reschedule keeps the sent media URLs and records the new time on the result.
            $times = [
                json_decode((string) $row->request, true)['scheduled_at'] ?? null,
                ...array_column($row->results ?? [], 'scheduled_at'),
            ];

            foreach ($times as $at) {
                if (is_string($at) && CarbonImmutable::parse($at)->addDays(self::SOCIALREINS_GRACE_DAYS)->isFuture()) {
                    return true;
                }
            }
        }

        return false;
    }
}
