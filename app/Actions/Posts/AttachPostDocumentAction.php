<?php

namespace App\Actions\Posts;

use App\Actions\Scratchpad\Concerns\ResolvesMediaAsset;
use App\Data\Posts\AttachPostDocumentData;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        return DB::transaction(function () use ($post, $uploadedBy, $data): Post {
            $lockedPost = Post::query()
                ->whereKey($post->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPost->isPublishInProgress() || $lockedPost->hasUncertainPublish()) {
                throw ValidationException::withMessages([
                    'publish' => __('A post cannot be edited while its PostSyncer publish is queued, running, or uncertain.'),
                ]);
            }

            $workspace = $lockedPost->workspace;
            if ($workspace === null) {
                throw new \RuntimeException('Post is missing a workspace.');
            }

            $mediaAsset = $this->resolveMediaAsset($workspace, $uploadedBy, $data->file, 'document');
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

            return $lockedPost->fresh(['attachments.mediaAsset']) ?? $lockedPost;
        });
    }
}
