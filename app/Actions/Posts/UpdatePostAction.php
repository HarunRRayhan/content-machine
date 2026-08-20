<?php

namespace App\Actions\Posts;

use App\Data\Posts\UpdatePostData;
use App\Models\Post;

/**
 * Edits a post's editable fields (title/body). Doesn't touch number/
 * human_id/status/idea_id, those are set once at promotion time and
 * aren't part of this slice's editing surface.
 */
class UpdatePostAction
{
    public function handle(Post $post, UpdatePostData $data): Post
    {
        $post->forceFill([
            'title' => $data->title,
            'body' => $data->body,
        ])->save();

        return $post;
    }
}
