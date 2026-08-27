<?php

namespace App\Support\Postsyncer;

use App\Models\Post;
use App\Models\Video;
use App\Support\GoogleDrive\GoogleDriveLink;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class MediaUrlResolver
{
    /**
     * @return list<string>
     */
    public function forPost(Post $post): array
    {
        $post->loadMissing(['attachments.mediaAsset']);

        if ($post->attachments->isNotEmpty()) {
            return $this->urlsFromAttachments($post);
        }

        return array_map(
            fn (string $url): string => GoogleDriveLink::toFetchUrl($url),
            array_values($post->image_drive_urls ?? []),
        );
    }

    /**
     * @return array{video: string, cover: ?string}
     */
    public function forVideo(Video $video): array
    {
        if (! filled($video->video_drive_url)) {
            throw new InvalidArgumentException('Video is missing video_drive_url.');
        }

        $cover = $video->cover_drive_url;

        return [
            'video' => GoogleDriveLink::toFetchUrl($video->video_drive_url),
            'cover' => is_string($cover) && $cover !== '' ? GoogleDriveLink::toFetchUrl($cover) : null,
        ];
    }

    /**
     * @return list<string>
     */
    private function urlsFromAttachments(Post $post): array
    {
        $urls = [];

        foreach ($post->attachments as $attachment) {
            $media = $attachment->mediaAsset;
            if ($media === null) {
                continue;
            }

            try {
                $url = Storage::disk($media->disk)->url($media->path);
            } catch (\Throwable) {
                continue;
            }

            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }
}
