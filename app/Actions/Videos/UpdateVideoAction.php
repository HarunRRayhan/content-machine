<?php

namespace App\Actions\Videos;

use App\Data\Videos\UpdateVideoData;
use App\Models\Video;
use Illuminate\Support\Facades\DB;

class UpdateVideoAction
{
    public function handle(Video $video, UpdateVideoData $data): Video
    {
        return DB::transaction(function () use ($video, $data): Video {
            $lockedVideo = Video::query()
                ->whereKey($video->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $attributes = [];

            if (! $data->replaceExtended || $data->hasTitle) {
                $attributes['title'] = $data->title;
            }

            if (! $data->replaceExtended || $data->hasBody) {
                $attributes['body'] = $data->body;
            }

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

            if (! $data->replaceExtended && $data->status !== null) {
                $attributes['status'] = $data->status;
            }

            if ($data->replaceExtended) {
                if ($data->hasLanguage) {
                    $attributes['language'] = $data->language;
                }
                if ($data->hasSlug) {
                    $attributes['slug'] = $data->slug;
                }
                if ($data->hasScriptMarkdown) {
                    $attributes['script_markdown'] = $data->scriptMarkdown;
                }
                if ($data->hasCaptions) {
                    $attributes['captions'] = $data->captions;
                }
                if ($data->hasDeckManifest) {
                    $attributes['deck_manifest'] = $data->deckManifest;
                }
                if ($data->hasStatus) {
                    $attributes['status'] = $data->status;
                }
            }

            $lockedVideo->forceFill($attributes)->save();

            return $lockedVideo;
        });
    }
}
