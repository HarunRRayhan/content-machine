<?php

namespace App\Actions\Videos;

use App\Data\Videos\UpdateVideoData;
use App\Models\Video;

/**
 * Edits a video's editable fields (title/body). Doesn't touch number/
 * human_id/status/idea_id, those are set once at promotion time and
 * aren't part of this slice's editing surface.
 */
class UpdateVideoAction
{
    public function handle(Video $video, UpdateVideoData $data): Video
    {
        $video->forceFill([
            'title' => $data->title,
            'body' => $data->body,
        ])->save();

        return $video;
    }
}
