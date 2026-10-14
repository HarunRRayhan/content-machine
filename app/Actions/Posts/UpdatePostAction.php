<?php

namespace App\Actions\Posts;

use App\Data\Posts\UpdatePostData;
use App\Models\Post;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdatePostAction
{
    public function handle(Post $post, UpdatePostData $data): Post
    {
        return DB::transaction(function () use ($post, $data): Post {
            $post = Post::query()
                ->whereKey($post->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $contentChanged = $this->contentChanged($post, $data);
            $checkpointedPublish = $this->hasCheckpointedPublishAttempt($post);
            $hasPublishedGroups = $this->hasPublishedGroups($post);
            $statusChanged = $data->status !== null && $data->status !== $post->status;

            $publishLocked = $checkpointedPublish || $hasPublishedGroups;
            $resetFailedPublish = $this->shouldResetFailedPublish(
                $post,
                $contentChanged,
                $publishLocked,
            );

            if (in_array($post->publish_state, ['queued', 'running'], true)
                && ($contentChanged
                    || $statusChanged
                    || $data->hasPostsyncer
                    || $data->hasPublishState
                    || $data->hasPublishError)
            ) {
                throw ValidationException::withMessages([
                    'post' => __('This post cannot be edited while a publish is in progress.'),
                ]);
            }

            if ($publishLocked
                && ($contentChanged
                    || ($statusChanged && ! $this->isSafePublishedStatusTransition($post, $data->status)))
            ) {
                throw ValidationException::withMessages([
                    'post' => __('This post cannot be edited after a PostSyncer publish attempt. Reconcile or retry it first.'),
                ]);
            }

            if ($publishLocked && $this->changesPublishMetadata($post, $data)) {
                throw ValidationException::withMessages([
                    'post' => __('PostSyncer publish metadata is managed by the publish workflow.'),
                ]);
            }

            $attributes = [
                'title' => $data->title,
            ];

            if ($data->hasBody) {
                $attributes['body'] = $data->body;
            }

            if ($data->hasCaptions) {
                $attributes['captions'] = $data->captions;
            }

            if ($data->hasImageDriveUrls) {
                $attributes['image_drive_urls'] = $data->imageDriveUrls;
            }

            if ($data->hasPostsyncer) {
                $attributes['postsyncer'] = $data->postsyncer;
            }

            if ($data->hasPublishState) {
                $attributes['publish_state'] = $data->publishState ?? 'idle';
            }

            if ($data->hasPublishError) {
                $attributes['publish_error'] = $data->publishError;
            }

            if ($data->hasTemplate) {
                $attributes['template'] = $data->template;
            }

            if ($data->status !== null) {
                $attributes['status'] = $data->status;
            }

            // The reason only means something while blocked; leaving the
            // state clears it so a stale blocker never outlives the block.
            if ($data->hasBlockedReason) {
                $attributes['blocked_reason'] = $data->blockedReason;
            }

            if (($data->status ?? $post->status) !== 'blocked') {
                $attributes['blocked_reason'] = null;
            }

            if ($post->approval_state === 'approved' && $contentChanged) {
                $post->invalidateApproval();
            }

            if ($data->replaceExtended) {
                $attributes['language'] = $data->language;
                $attributes['slug'] = $data->slug;
                $attributes['captions'] = $data->captions;
                $attributes['platforms'] = $data->platforms;
                $attributes['status'] = $data->status ?? $post->status;
                if ($data->hasTemplate) {
                    $attributes['template'] = $data->template;
                }
            }

            // A deterministic failure with no external post can be retried as
            // a new operation after the content is edited.
            if ($resetFailedPublish) {
                $attributes['publish_state'] = 'idle';
                $attributes['publish_error'] = null;
                $attributes['publish_progress'] = null;
            }

            $post->forceFill($attributes)->save();

            return $post;
        });
    }

    private function contentChanged(Post $post, UpdatePostData $data): bool
    {
        if ($post->title !== $data->title) {
            return true;
        }

        if ($data->hasBody && $post->body !== $data->body) {
            return true;
        }

        if ($data->hasCaptions && $post->captions !== $data->captions) {
            return true;
        }

        if ($data->hasImageDriveUrls && $post->image_drive_urls !== $data->imageDriveUrls) {
            return true;
        }

        if ($data->replaceExtended && $post->language !== $data->language) {
            return true;
        }

        if ($data->replaceExtended && $post->slug !== $data->slug) {
            return true;
        }

        return $data->replaceExtended && $post->platforms !== $data->platforms;
    }

    private function changesPublishMetadata(Post $post, UpdatePostData $data): bool
    {
        return ($data->hasPostsyncer && $data->postsyncer !== $post->postsyncer)
            || ($data->hasPublishState && $data->publishState !== $post->publish_state)
            || ($data->hasPublishError && $data->publishError !== $post->publish_error);
    }

    private function hasCheckpointedPublishAttempt(Post $post): bool
    {
        $progress = $post->publish_progress;

        if (! is_array($progress)) {
            return false;
        }

        return ($progress['current'] ?? null) !== null
            || ($progress['state'] ?? null) === 'uncertain'
            || (is_array($progress['completed_groups'] ?? null)
                && $progress['completed_groups'] !== []);
    }

    private function hasPublishedGroups(Post $post): bool
    {
        $groups = $post->postsyncer['groups'] ?? null;

        if (! is_array($groups)) {
            return false;
        }

        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $postId = $group['post_id'] ?? null;
            if (is_int($postId) || is_float($postId)) {
                if ($postId > 0) {
                    return true;
                }
            } elseif (is_string($postId) && trim($postId) !== '') {
                return true;
            }
        }

        return false;
    }

    private function isSafePublishedStatusTransition(Post $post, ?string $nextStatus): bool
    {
        if (($post->status === 'posted' && $nextStatus === 'archived')
            || ($post->status === 'archived' && $nextStatus === 'posted')) {
            return true;
        }

        if (! $this->publishSettled($post)) {
            return false;
        }

        // Settled groups are all PUBLISHED or FAILED, so the only exit from
        // blocked is posted or archived. draft would reopen groups that exist
        // and scheduled would claim groups that are no longer pending.
        return $nextStatus === 'blocked'
            || ($post->status === 'blocked' && in_array($nextStatus, ['posted', 'archived'], true));
    }

    /**
     * No publish job is in flight and every PostSyncer group is terminal.
     * A post with no groups counts as settled.
     */
    private function publishSettled(Post $post): bool
    {
        if (in_array($post->publish_state, ['queued', 'running'], true)) {
            return false;
        }

        $progressState = is_array($post->publish_progress) ? ($post->publish_progress['state'] ?? null) : null;
        if (in_array($progressState, ['queued', 'running', 'uncertain'], true)) {
            return false;
        }

        $groups = is_array($post->postsyncer) ? ($post->postsyncer['groups'] ?? []) : [];

        foreach (is_array($groups) ? $groups : [] as $group) {
            $rawStatus = is_array($group) ? ($group['status'] ?? null) : null;
            $status = is_string($rawStatus) ? strtoupper($rawStatus) : '';

            if (! in_array($status, ['PUBLISHED', 'FAILED'], true)) {
                return false;
            }
        }

        return true;
    }

    private function shouldResetFailedPublish(Post $post, bool $contentChanged, bool $publishLocked): bool
    {
        return $contentChanged
            && $post->publish_state === 'failed'
            && ! $publishLocked;
    }
}
