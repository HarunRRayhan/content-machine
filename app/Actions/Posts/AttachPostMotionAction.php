<?php

namespace App\Actions\Posts;

use App\Actions\Scratchpad\Concerns\ResolvesMediaAsset;
use App\Data\Posts\AttachPostMotionData;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stores an uploaded MP4 slide on the private `scratchpad` disk and attaches
 * it with role `motion` for one platform + language. Motion slides preview a
 * video carousel on the Captions tab only; publishing ignores them. Attaching
 * the same bytes to the same slot again is a no-op.
 */
class AttachPostMotionAction
{
    use ResolvesMediaAsset;

    public function handle(Post $post, ?User $uploadedBy, AttachPostMotionData $data): Post
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

            $mediaAsset = $this->resolveMediaAsset($workspace, $uploadedBy, $data->file, 'video', $data->filename);

            // Identical bytes reuse one MediaAsset that keeps its first name. A different
            // name would silently miss the caption's motion list, so refuse it instead.
            if ($mediaAsset->original_filename !== $data->filename) {
                throw ValidationException::withMessages([
                    'file' => __('These bytes are already stored as :name. Upload them under that name or send different bytes.', [
                        'name' => $mediaAsset->original_filename,
                    ]),
                ]);
            }
            $slot = $lockedPost->attachments()
                ->where('role', 'motion')
                ->where('platform', $data->platform)
                ->where('language', $data->language);

            if ((clone $slot)->where('media_asset_id', $mediaAsset->id)->exists()) {
                return $lockedPost->fresh(['attachments.mediaAsset']) ?? $lockedPost;
            }

            $maxPosition = (clone $slot)->max('position');
            $position = $data->order ?? ($maxPosition === null ? 0 : ((int) $maxPosition) + 1);

            $lockedPost->attachments()->create([
                'media_asset_id' => $mediaAsset->id,
                'role' => 'motion',
                'platform' => $data->platform,
                'language' => $data->language,
                'position' => $position,
            ]);

            return $lockedPost->fresh(['attachments.mediaAsset']) ?? $lockedPost;
        });
    }
}
