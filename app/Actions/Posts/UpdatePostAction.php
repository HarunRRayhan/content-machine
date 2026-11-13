<?php

namespace App\Actions\Posts;

use App\Data\Posts\UpdatePostData;
use App\Http\Requests\Settings\UpdatePostsyncerSettingsRequest;
use App\Models\Post;
use App\Support\Content\PublishGroups;
use App\Support\Postsyncer\PostPublishPlanner;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdatePostAction
{
    public function __construct(
        private readonly ?PostPublishPlanner $planner = null,
    ) {}

    public function handle(Post $post, UpdatePostData $data): Post
    {
        return DB::transaction(function () use ($post, $data): Post {
            $post = Post::query()
                ->whereKey($post->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $contentChanged = $this->contentChanged($post, $data);
            $checkpointedPublish = $this->hasCheckpointedPublishAttempt($post);
            // Both drivers, read once: a post handed to SocialReins is locked like one handed to PostSyncer.
            $groups = PublishGroups::of($post);
            $hasPublishedGroups = $this->hasPublishedGroups($groups);
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
                && (($contentChanged && ! $this->isAdditivePlatformChange($post, $data, $groups))
                    || ($statusChanged && ! $this->isSafePublishedStatusTransition($post, $data->status, $groups)))
            ) {
                throw ValidationException::withMessages([
                    'post' => __('This post cannot be edited after a publish attempt. Reconcile or retry it first.'),
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

            // Category is calendar metadata: not content, so approval and
            // publish locks are untouched.
            if ($data->category !== null) {
                $attributes['category'] = $data->category;
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

        if ($data->hasCaptions && $this->canonical($post->captions) !== $this->canonical($data->captions)) {
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

    /**
     * A publish-locked post may only gain caption sections (and platforms
     * entries) for platforms no publish group has touched yet, so the
     * append-missing publish flow has a caption to schedule. Every existing
     * section and every other content field must stay exactly as stored,
     * except the section of a platform SocialReins left out as invalid:
     * nothing of it exists anywhere, and its caption has to change before a
     * retry can send it.
     *
     * @param  list<array<string, mixed>>  $groups  the post's PublishGroups
     */
    private function isAdditivePlatformChange(Post $post, UpdatePostData $data, array $groups): bool
    {
        if ($post->title !== $data->title
            || ($data->hasBody && $post->body !== $data->body)
            || ($data->hasImageDriveUrls && $post->image_drive_urls !== $data->imageDriveUrls)
            || ($data->replaceExtended
                && ($post->language !== $data->language || $post->slug !== $data->slug))
        ) {
            return false;
        }

        if ($data->replaceExtended
            && array_diff($post->platforms ?? [], $data->platforms ?? []) !== []
        ) {
            return false;
        }

        if (! $data->hasCaptions && ! $data->replaceExtended) {
            return true;
        }

        // "language:platform" of every platform SocialReins left out and no later group sent.
        $skipped = [];
        foreach ($groups as $group) {
            if (($group['skipped'] ?? false) === true) {
                $skipped[] = $group['language'].':'.$group['platforms'][0];
            }
        }

        $added = $this->addedCaptionPlatforms(
            $post->captions ?? [],
            $data->captions ?? [],
            array_map(fn (string $key): string => explode(':', $key, 2)[1], $skipped),
        );

        if ($added === null
            || array_diff($added, UpdatePostsyncerSettingsRequest::PLATFORMS) !== []
            || array_intersect($added, $this->groupPlatforms($post, $groups)) !== []
        ) {
            return false;
        }

        return $this->onlyAddsPlannedPairs($post, $data->captions ?? [], $added, $skipped);
    }

    /**
     * Second check through the publish planner's own caption reading: every
     * (language, platform) entry it reads today must read the same after the
     * edit, and any new entry must be one of the raw platforms just added.
     * Only a "language:platform" in `$skipped` may read differently.
     *
     * @param  array<mixed>  $captions
     * @param  list<string>  $added
     * @param  list<string>  $skipped
     */
    private function onlyAddsPlannedPairs(Post $post, array $captions, array $added, array $skipped = []): bool
    {
        $planner = $this->planner ?? app(PostPublishPlanner::class);
        $stored = $post->captions ?? [];

        // jsonb reorders object keys, so a new part sharing a language with
        // another part could later merge over it in a different order than
        // validated here. A new part must bring a language of its own.
        if (! $this->isFlatCaptionMap($stored)) {
            $languages = [];
            foreach (array_keys($stored) as $part) {
                $languages[$planner->partLanguage($post, (string) $part)] = true;
            }

            foreach (array_keys(array_diff_key($captions, $stored)) as $part) {
                $language = $planner->partLanguage($post, (string) $part);
                if (isset($languages[$language])) {
                    return false;
                }
                $languages[$language] = true;
            }
        }

        $before = $planner->captionsByLanguage($post);
        $after = $planner->captionsByLanguage((clone $post)->forceFill(['captions' => $captions]));

        foreach ($before as $language => $platforms) {
            foreach ($platforms as $platform => $section) {
                if ($this->canonical($section) !== $this->canonical($after[$language][$platform] ?? null)
                    && ! in_array("{$language}:{$platform}", $skipped, true)) {
                    return false;
                }
            }
        }

        foreach ($after as $language => $platforms) {
            foreach (array_keys(array_diff_key($platforms, $before[$language] ?? [])) as $platform) {
                if (! in_array($platform, $added, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Platform names whose caption sections were added, or null when any
     * stored section changed or disappeared, or the map would change shape.
     * A flat {platform: section} map may only gain sections; a nested
     * {part: {platform: section}} map may only gain parts or platforms
     * inside a part. The shape test mirrors PostPublishPlanner, so an added
     * entry can never make the planner re-read the stored captions.
     * A stored section of a platform in `$editable` may change, not disappear.
     *
     * @param  array<mixed>  $old
     * @param  array<mixed>  $new
     * @param  list<string>  $editable
     * @return list<string>|null
     */
    private function addedCaptionPlatforms(array $old, array $new, array $editable = []): ?array
    {
        $flat = $this->isFlatCaptionMap($old);

        if ($flat !== $this->isFlatCaptionMap($new)) {
            return null;
        }

        $added = [];

        foreach ($old as $key => $value) {
            if (! array_key_exists($key, $new)) {
                return null;
            }

            if ($this->canonical($value) === $this->canonical($new[$key])
                || ($flat && $this->isPlatformSection($new[$key]) && in_array(strtolower((string) $key), $editable, true))) {
                continue;
            }

            if ($flat || ! is_array($value) || ! is_array($new[$key])) {
                return null;
            }

            foreach ($value as $platform => $section) {
                if (! array_key_exists($platform, $new[$key])
                    || ($this->canonical($section) !== $this->canonical($new[$key][$platform])
                        && ! ($this->isPlatformSection($new[$key][$platform]) && in_array(strtolower((string) $platform), $editable, true)))
                ) {
                    return null;
                }
            }

            foreach (array_keys(array_diff_key($new[$key], $value)) as $platform) {
                $added[] = strtolower((string) $platform);
            }
        }

        foreach (array_diff_key($new, $old) as $key => $value) {
            if ($flat && $this->isPlatformSection($value)) {
                $added[] = strtolower((string) $key);
            } elseif (! $flat && is_array($value) && ! $this->isPlatformSection($value)) {
                foreach (array_keys($value) as $platform) {
                    $added[] = strtolower((string) $platform);
                }
            } else {
                return null;
            }
        }

        return $added;
    }

    /**
     * Same test as PostPublishPlanner::isFlatCaptionMap.
     *
     * @param  array<mixed>  $captions
     */
    private function isFlatCaptionMap(array $captions): bool
    {
        foreach ($captions as $key => $value) {
            if (is_int($key)) {
                return false;
            }

            if ($this->isPlatformSection($value)) {
                return true;
            }
        }

        return false;
    }

    private function isPlatformSection(mixed $value): bool
    {
        return is_string($value)
            || (is_array($value) && (array_key_exists('caption', $value) || array_key_exists('title', $value)));
    }

    /** jsonb does not keep object key order, so compare maps order-insensitively. */
    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->canonical($item), $value);
    }

    /**
     * Every platform in a stored or checkpointed publish group, whatever its status.
     *
     * @param  list<array<string, mixed>>  $groups  the post's PublishGroups
     * @return list<string>
     */
    private function groupPlatforms(Post $post, array $groups): array
    {
        $groups = array_merge(
            $groups,
            is_array($post->publish_progress['completed_groups'] ?? null) ? $post->publish_progress['completed_groups'] : [],
        );

        $platforms = [];
        foreach ($groups as $group) {
            $list = is_array($group) && is_array($group['platforms'] ?? null) ? $group['platforms'] : [];
            foreach ($list as $platform) {
                $platforms[] = strtolower((string) $platform);
            }
        }

        return $platforms;
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

    /** @param  list<array<string, mixed>>  $groups  the post's PublishGroups */
    private function hasPublishedGroups(array $groups): bool
    {
        foreach ($groups as $group) {
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

    /** @param  list<array<string, mixed>>  $groups  the post's PublishGroups */
    private function isSafePublishedStatusTransition(Post $post, ?string $nextStatus, array $groups): bool
    {
        if (($post->status === 'posted' && $nextStatus === 'archived')
            || ($post->status === 'archived' && $nextStatus === 'posted')) {
            return true;
        }

        if (! $this->publishSettled($post, $groups)) {
            return false;
        }

        // Settled groups are all PUBLISHED or FAILED, so the only exit from
        // blocked is posted or archived. draft would reopen groups that exist
        // and scheduled would claim groups that are no longer pending.
        return $nextStatus === 'blocked'
            || ($post->status === 'blocked' && in_array($nextStatus, ['posted', 'archived'], true));
    }

    /**
     * No publish job is in flight and every publish group is terminal.
     * A post with no groups counts as settled.
     *
     * @param  list<array<string, mixed>>  $groups  the post's PublishGroups
     */
    private function publishSettled(Post $post, array $groups): bool
    {
        if (in_array($post->publish_state, ['queued', 'running'], true)) {
            return false;
        }

        $progressState = is_array($post->publish_progress) ? ($post->publish_progress['state'] ?? null) : null;
        if (in_array($progressState, ['queued', 'running', 'uncertain'], true)) {
            return false;
        }

        foreach ($groups as $group) {
            $rawStatus = $group['status'] ?? null;
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
