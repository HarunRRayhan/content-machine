<?php

namespace App\Data\Videos;

use App\Http\Requests\Videos\UpdateVideoRequest;
use App\Models\Video;

/**
 * Editable surface for a video. Dashboard updates only title/body
 * (`replaceExtended=false`); API PATCH replaces the extended columns too.
 */
final readonly class UpdateVideoData
{
    /**
     * @param  array<string, mixed>|null  $captions
     * @param  array<string, mixed>|null  $deckManifest
     */
    public function __construct(
        public string $title,
        public ?string $body = null,
        public ?string $language = null,
        public ?string $slug = null,
        public ?string $scriptMarkdown = null,
        public ?array $captions = null,
        public ?array $deckManifest = null,
        public ?string $status = null,
        public bool $replaceExtended = false,
    ) {}

    public static function fromRequest(UpdateVideoRequest $request): self
    {
        return new self(
            title: $request->string('title')->toString(),
            body: $request->filled('body') ? $request->string('body')->toString() : null,
            replaceExtended: false,
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
            status: array_key_exists('status', $payload) ? (string) $payload['status'] : $current->status,
            replaceExtended: true,
        );
    }
}
