<?php

namespace App\Support\Postsyncer;

use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\Video;
use App\Support\GoogleDrive\GoogleDriveLink;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;

class MediaUrlResolver
{
    private const SIGNED_URL_TTL_HOURS = 3;

    /**
     * @return list<string>
     */
    public function forPost(Post $post): array
    {
        $post->loadMissing(['attachments.mediaAsset']);

        if ($post->attachments->isNotEmpty()) {
            $attachmentUrls = $this->attachmentUrlsInOrder($post);
            if ($attachmentUrls !== []) {
                return $attachmentUrls;
            }
        }

        return array_map(
            fn (string $url): string => GoogleDriveLink::toFetchUrl($url),
            array_values($post->image_drive_urls ?? []),
        );
    }

    /**
     * Resolve platform image entries: Drive URLs, or attachment filenames from imports.
     *
     * @param  list<string>  $images
     * @return list<string>
     */
    public function resolveNamedImages(Post $post, array $images): array
    {
        if ($images === []) {
            return [];
        }

        $byFilename = $this->attachmentUrlsByFilename($post);
        $urls = [];

        foreach ($images as $image) {
            $image = trim($image);
            if ($image === '') {
                continue;
            }

            if (str_starts_with($image, 'http://') || str_starts_with($image, 'https://')) {
                $urls[] = GoogleDriveLink::toFetchUrl($image);

                continue;
            }

            if (isset($byFilename[$image])) {
                $urls[] = $byFilename[$image];
            }
        }

        return $urls;
    }

    /**
     * First attached post document (LinkedIn carousel PDF), if any.
     */
    public function linkedinDocumentUrl(Post $post): ?string
    {
        $post->loadMissing(['attachments.mediaAsset']);

        foreach ($post->attachments->sortBy('position') as $attachment) {
            if ($attachment->role !== 'document') {
                continue;
            }

            $media = $attachment->mediaAsset;
            if ($media === null || ! $this->attachmentIsReadable($media)) {
                continue;
            }

            return $this->signedPostMediaUrl($post, $media);
        }

        return null;
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
    private function attachmentUrlsInOrder(Post $post): array
    {
        $urls = [];

        foreach ($post->attachments->sortBy('position') as $attachment) {
            $media = $attachment->mediaAsset;
            if ($media === null || ! $this->attachmentIsReadable($media)) {
                continue;
            }

            $urls[] = $this->signedPostMediaUrl($post, $media);
        }

        return $urls;
    }

    /**
     * @return array<string, string>
     */
    private function attachmentUrlsByFilename(Post $post): array
    {
        $post->loadMissing(['attachments.mediaAsset']);
        $map = [];

        foreach ($post->attachments->sortBy('position') as $attachment) {
            $media = $attachment->mediaAsset;
            if ($media === null || ! $this->attachmentIsReadable($media)) {
                continue;
            }

            $url = $this->signedPostMediaUrl($post, $media);

            if (filled($media->original_filename)) {
                $map[$media->original_filename] = $url;
            }

            $basename = basename($media->path);
            if ($basename !== '') {
                $map[$basename] = $url;
            }
        }

        return $map;
    }

    private function signedPostMediaUrl(Post $post, MediaAsset $media): string
    {
        return URL::temporarySignedRoute(
            'publish-media.post',
            now()->addHours(self::SIGNED_URL_TTL_HOURS),
            ['post' => $post->id, 'mediaAsset' => $media->id],
            absolute: true,
        );
    }

    private function attachmentIsReadable(MediaAsset $media): bool
    {
        if (! is_array(config("filesystems.disks.{$media->disk}"))) {
            return false;
        }

        try {
            return Storage::disk($media->disk)->exists($media->path);
        } catch (\Throwable) {
            return false;
        }
    }
}
