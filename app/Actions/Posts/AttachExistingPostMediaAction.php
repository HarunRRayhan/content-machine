<?php

namespace App\Actions\Posts;

use App\Models\MediaAsset;
use App\Models\Post;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reuses a media asset that was already captured into the Scratch Pad. This
 * keeps a Telegram photo from being copied a second time when it becomes a
 * post attachment.
 */
class AttachExistingPostMediaAction
{
    public function handle(Post $post, MediaAsset $mediaAsset, string $role = 'image'): Post
    {
        if ($post->workspace_id !== $mediaAsset->workspace_id) {
            throw new RuntimeException('Post and media asset belong to different workspaces.');
        }

        if ($role === 'image' && $mediaAsset->kind !== 'image') {
            throw new RuntimeException('Only image media can be attached as a post image.');
        }

        return DB::transaction(function () use ($post, $mediaAsset, $role): Post {
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
                'role' => $role,
                'position' => $position,
            ]);

            $lockedPost->invalidateApproval();

            return $lockedPost->fresh(['attachments.mediaAsset']) ?? $lockedPost;
        });
    }
}
