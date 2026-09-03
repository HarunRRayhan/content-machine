<?php

namespace App\Data\Posts;

use App\Http\Requests\Posts\UpdatePostRequest;
use App\Models\Post;
use App\Support\Media\PostDesignTemplate;

/**
 * Editable surface for a post. Drive URL columns are written only when
 * the request sent that key. API PATCH still replaces extended columns.
 */
final readonly class UpdatePostData
{
    /**
     * @param  array<string, mixed>|null  $captions
     * @param  array<string, mixed>|null  $platforms
     * @param  array<int, string>|null  $imageDriveUrls
     * @param  array<string, mixed>|null  $postsyncer
     */
    public function __construct(
        public string $title,
        public ?string $body = null,
        public ?string $language = null,
        public ?string $slug = null,
        public ?string $template = null,
        public ?array $captions = null,
        public ?array $platforms = null,
        public ?array $imageDriveUrls = null,
        public ?string $status = null,
        public ?array $postsyncer = null,
        public ?string $publishState = null,
        public ?string $publishError = null,
        public bool $replaceExtended = false,
        public bool $hasBody = true,
        public bool $hasCaptions = false,
        public bool $hasImageDriveUrls = false,
        public bool $hasPostsyncer = false,
        public bool $hasPublishState = false,
        public bool $hasPublishError = false,
        public bool $hasTemplate = false,
    ) {}

    public static function fromRequest(UpdatePostRequest $request): self
    {
        return new self(
            title: $request->string('title')->toString(),
            body: $request->filled('body') ? $request->string('body')->toString() : null,
            captions: $request->has('captions') && is_array($request->input('captions'))
                ? $request->input('captions')
                : null,
            status: $request->filled('status') ? $request->string('status')->toString() : null,
            imageDriveUrls: self::parseDriveUrls($request->input('image_drive_urls')),
            replaceExtended: false,
            hasBody: $request->has('body'),
            hasCaptions: $request->has('captions'),
            hasImageDriveUrls: $request->has('image_drive_urls'),
        );
    }

    /**
     * @return array<int, string>|null
     */
    public static function parseDriveUrls(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_array($raw)) {
            $urls = array_values(array_filter(array_map(
                static fn ($value) => is_string($value) ? trim($value) : '',
                $raw,
            ), static fn (string $value) => $value !== ''));

            return $urls === [] ? null : $urls;
        }

        if (! is_string($raw)) {
            return null;
        }

        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $urls = array_values(array_filter(array_map('trim', $lines), static fn (string $line) => $line !== ''));

        return $urls === [] ? null : $urls;
    }

    public static function normalizeTemplate(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (! is_string($raw)) {
            return null;
        }

        $letter = strtoupper(trim($raw));
        if (! in_array($letter, PostDesignTemplate::LETTERS, true)) {
            return null;
        }

        return $letter;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromApiPayload(array $payload, Post $current): self
    {
        return new self(
            title: array_key_exists('title', $payload) ? (string) $payload['title'] : $current->title,
            body: array_key_exists('body', $payload) ? ($payload['body'] !== null ? (string) $payload['body'] : null) : $current->body,
            language: array_key_exists('language', $payload) ? ($payload['language'] !== null ? (string) $payload['language'] : null) : $current->language,
            slug: array_key_exists('slug', $payload) ? ($payload['slug'] !== null ? (string) $payload['slug'] : null) : $current->slug,
            template: array_key_exists('template', $payload)
                ? self::normalizeTemplate($payload['template'])
                : $current->template,
            captions: array_key_exists('captions', $payload) ? (is_array($payload['captions']) ? $payload['captions'] : null) : $current->captions,
            platforms: array_key_exists('platforms', $payload) ? (is_array($payload['platforms']) ? $payload['platforms'] : null) : $current->platforms,
            status: array_key_exists('status', $payload) ? (string) $payload['status'] : $current->status,
            imageDriveUrls: array_key_exists('image_drive_urls', $payload)
                ? self::parseDriveUrls($payload['image_drive_urls'])
                : null,
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
            hasBody: true,
            hasCaptions: array_key_exists('captions', $payload),
            hasImageDriveUrls: array_key_exists('image_drive_urls', $payload),
            hasPostsyncer: array_key_exists('postsyncer', $payload),
            hasPublishState: array_key_exists('publish_state', $payload),
            hasPublishError: array_key_exists('publish_error', $payload),
            hasTemplate: array_key_exists('template', $payload),
        );
    }
}
