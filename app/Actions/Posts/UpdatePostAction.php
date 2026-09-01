<?php

namespace App\Actions\Posts;

use App\Data\Posts\UpdatePostData;
use App\Models\Post;
use App\Models\TelegramPostRequest;

class UpdatePostAction
{
    public function handle(Post $post, UpdatePostData $data): Post
    {
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

        if ($post->approval_state === 'approved' && $this->contentChanged($post, $data)) {
            $attributes['approval_state'] = 'pending';
            $attributes['approved_at'] = null;
            $attributes['approved_by_user_id'] = null;

            $post->telegramPostRequests()
                ->whereIn('state', [
                    TelegramPostRequest::APPROVED,
                    TelegramPostRequest::FAILED,
                ])
                ->update([
                    'state' => TelegramPostRequest::AWAITING_APPROVAL,
                    'confirmed_at' => null,
                    'error_message' => null,
                ]);
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

        $post->forceFill($attributes)->save();

        return $post;
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
}
