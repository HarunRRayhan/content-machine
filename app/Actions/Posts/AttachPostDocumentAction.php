<?php

namespace App\Actions\Posts;

use App\Actions\Scratchpad\Concerns\ResolvesMediaAsset;
use App\Data\Posts\AttachPostDocumentData;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Stores an uploaded post document (LinkedIn carousel PDF) on the private
 * `scratchpad` disk and attaches it with role `document`. Re-uploading the
 * same bytes onto the same post is a no-op.
 */
class AttachPostDocumentAction
{
    use ResolvesMediaAsset;

    public function handle(Post $post, ?User $uploadedBy, AttachPostDocumentData $data): Post
    {
        $workspace = $post->workspace;
        if ($workspace === null) {
            throw new \RuntimeException('Post is missing a workspace.');
        }

        $mediaAsset = $this->resolveMediaAsset($workspace, $uploadedBy, $data->file, 'document');

        return DB::transaction(function () use ($post, $mediaAsset): Post {
            $lockedPost = Post::query()
                ->whereKey($post->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $existing = $lockedPost->attachments()
                ->where('media_asset_id', $mediaAsset->id)
                ->first();

            if ($existing !== null) {
                return $lockedPost->fresh(['attachments.mediaAsset']) ?? $lockedPost;
            }

            $maxPosition = $lockedPost->attachments()->max('position');
            $position = $maxPosition === null ? 0 : ((int) $maxPosition) + 1;

            $lockedPost->attachments()->create([
                'media_asset_id' => $mediaAsset->id,
                'role' => 'document',
                'platform' => 'linkedin',
                'position' => $position,
            ]);

            $lockedPost->invalidateApproval();

            return $lockedPost->fresh(['attachments.mediaAsset']) ?? $lockedPost;
        });
    }
}
