<?php

namespace App\Actions\Videos;

use App\Data\Videos\UpdateVideoData;
use App\Models\Video;

class UpdateVideoAction
{
    public function handle(Video $video, UpdateVideoData $data): Video
    {
        $attributes = [
            'title' => $data->title,
            'body' => $data->body,
        ];

        if ($data->replaceExtended) {
            $attributes['language'] = $data->language;
            $attributes['slug'] = $data->slug;
            $attributes['script_markdown'] = $data->scriptMarkdown;
            $attributes['captions'] = $data->captions;
            $attributes['deck_manifest'] = $data->deckManifest;
            $attributes['status'] = $data->status ?? $video->status;
        }

        $video->forceFill($attributes)->save();

        return $video;
    }
}
