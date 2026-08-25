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
            'video_drive_url' => $data->videoDriveUrl,
            'cover_drive_url' => $data->coverDriveUrl,
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
