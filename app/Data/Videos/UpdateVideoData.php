<?php

namespace App\Data\Videos;

use App\Http\Requests\Videos\UpdateVideoRequest;
use App\Models\Video;

/**
 * Editable surface for a video. Dashboard updates title/body and API PATCH
 * writes only the fields present in its payload.
 */
final readonly class UpdateVideoData
{
    /**
     * @param  array<string, mixed>|null  $captions
     * @param  array<string, mixed>|null  $deckManifest
     * @param  array<string, mixed>|null  $postsyncer
     */
    public function __construct(
        public string $title,
        public ?string $body = null,
        public ?string $language = null,
        public ?string $slug = null,
        public ?string $scriptMarkdown = null,
        public ?array $captions = null,
        public ?array $deckManifest = null,
        public ?string $videoDriveUrl = null,
        public ?string $coverDriveUrl = null,
        public ?string $status = null,
        public ?array $postsyncer = null,
        public ?string $publishState = null,
        public ?string $publishError = null,
        public bool $replaceExtended = false,
        public bool $hasVideoDriveUrl = false,
        public bool $hasCoverDriveUrl = false,
        public bool $hasPostsyncer = false,
        public bool $hasPublishState = false,
        public bool $hasPublishError = false,
        public bool $hasTitle = true,
        public bool $hasBody = true,
        public bool $hasLanguage = false,
        public bool $hasSlug = false,
        public bool $hasScriptMarkdown = false,
        public bool $hasCaptions = false,
        public bool $hasDeckManifest = false,
        public bool $hasStatus = false,
    ) {}

    public static function fromRequest(UpdateVideoRequest $request): self
    {
        return new self(
            title: $request->string('title')->toString(),
            body: $request->filled('body') ? $request->string('body')->toString() : null,
            status: $request->filled('status') ? $request->string('status')->toString() : null,
            videoDriveUrl: $request->filled('video_drive_url') ? $request->string('video_drive_url')->toString() : null,
            coverDriveUrl: $request->filled('cover_drive_url') ? $request->string('cover_drive_url')->toString() : null,
            replaceExtended: false,
            hasVideoDriveUrl: $request->has('video_drive_url'),
            hasCoverDriveUrl: $request->has('cover_drive_url'),
            hasBody: true,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromApiPayload(array $payload, Video $current): self
    {
        return new self(
            title: array_key_exists('title', $payload) ? (string) $payload['title'] : $current->title,
            body: array_key_exists('body', $payload) ? ($payload['body'] !== null ? (string) $payload['body'] : null) : $current->body,
            language: array_key_exists('language', $payload) ? ($payload['language'] !== null ? (string) $payload['language'] : null) : $current->language,
            slug: array_key_exists('slug', $payload) ? ($payload['slug'] !== null ? (string) $payload['slug'] : null) : $current->slug,
            scriptMarkdown: array_key_exists('script_markdown', $payload) ? ($payload['script_markdown'] !== null ? (string) $payload['script_markdown'] : null) : $current->script_markdown,
            captions: array_key_exists('captions', $payload) ? (is_array($payload['captions']) ? $payload['captions'] : null) : $current->captions,
            deckManifest: array_key_exists('deck_manifest', $payload) ? (is_array($payload['deck_manifest']) ? $payload['deck_manifest'] : null) : $current->deck_manifest,
            videoDriveUrl: array_key_exists('video_drive_url', $payload) ? ($payload['video_drive_url'] !== null ? (string) $payload['video_drive_url'] : null) : null,
            coverDriveUrl: array_key_exists('cover_drive_url', $payload) ? ($payload['cover_drive_url'] !== null ? (string) $payload['cover_drive_url'] : null) : null,
            status: array_key_exists('status', $payload) ? (string) $payload['status'] : $current->status,
            postsyncer: array_key_exists('postsyncer', $payload) && is_array($payload['postsyncer'])
                ? $payload['postsyncer']
                : null,
            publishState: array_key_exists('publish_state', $payload)
                ? ($payload['publish_state'] !== null ? (string) $payload['publish_state'] : null)
                : null,
            publishError: array_key_exists('publish_error', $payload)
                ? ($payload['publish_error'] !== null ? (string) $payload['publish_error'] : null)
                : null,
            replaceExtended: true,
            hasTitle: array_key_exists('title', $payload),
            hasBody: array_key_exists('body', $payload),
            hasLanguage: array_key_exists('language', $payload),
            hasSlug: array_key_exists('slug', $payload),
            hasScriptMarkdown: array_key_exists('script_markdown', $payload),
            hasCaptions: array_key_exists('captions', $payload),
            hasDeckManifest: array_key_exists('deck_manifest', $payload),
            hasStatus: array_key_exists('status', $payload),
            hasVideoDriveUrl: array_key_exists('video_drive_url', $payload),
            hasCoverDriveUrl: array_key_exists('cover_drive_url', $payload),
            hasPostsyncer: array_key_exists('postsyncer', $payload),
            hasPublishState: array_key_exists('publish_state', $payload),
            hasPublishError: array_key_exists('publish_error', $payload),
        );
    }
}
