<?php

namespace App\Actions\Videos;

use App\Data\Videos\UpdateVideoData;
use App\Models\Video;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateVideoAction
{
    public function handle(Video $video, UpdateVideoData $data): Video
    {
        $attributes = [
            'title' => $data->title,
            'body' => $data->body,
        ];

        if ($data->hasVideoDriveUrl) {
            $attributes['video_drive_url'] = $data->videoDriveUrl;
        }

        if ($data->hasCoverDriveUrl) {
            $attributes['cover_drive_url'] = $data->coverDriveUrl;
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
            $attributes['script_markdown'] = $data->scriptMarkdown;
            $attributes['captions'] = $data->captions;
            $attributes['deck_manifest'] = $data->deckManifest;
            if ($data->status !== null) {
                $attributes['status'] = $data->status;
            } else {
                $attributes['status'] = $video->status;
            }
        }

        return DB::transaction(function () use ($video, $attributes): Video {
            $lockedVideo = Video::query()
                ->whereKey($video->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedVideo->isPublishInProgress() || $lockedVideo->hasUncertainPublish()) {
                throw ValidationException::withMessages([
                    'publish' => __('A video cannot be edited while its PostSyncer publish is queued, running, or uncertain.'),
                ]);
            }

            $lockedVideo->forceFill($attributes)->save();

            return $lockedVideo;
        });
    }
}
