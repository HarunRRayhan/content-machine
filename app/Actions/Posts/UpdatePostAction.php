<?php

namespace App\Actions\Posts;

use App\Data\Posts\UpdatePostData;
use App\Models\Post;

class UpdatePostAction
{
    public function handle(Post $post, UpdatePostData $data): Post
    {
        $attributes = [
            'title' => $data->title,
            'body' => $data->body,
        ];

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

        if ($data->status !== null) {
            $attributes['status'] = $data->status;
        }

        if ($data->replaceExtended) {
            $attributes['language'] = $data->language;
            $attributes['slug'] = $data->slug;
            $attributes['captions'] = $data->captions;
            $attributes['platforms'] = $data->platforms;
            $attributes['status'] = $data->status ?? $post->status;
        }

        $post->forceFill($attributes)->save();

        return $post;
    }
}
