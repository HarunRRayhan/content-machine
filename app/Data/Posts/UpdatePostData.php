<?php

namespace App\Data\Posts;

use App\Http\Requests\Posts\UpdatePostRequest;
use App\Models\Post;

final readonly class UpdatePostData
{
    /**
     * @param  array<string, mixed>|null  $captions
     * @param  array<string, mixed>|null  $platforms
     */
    public function __construct(
        public string $title,
        public ?string $body = null,
        public ?string $language = null,
        public ?string $slug = null,
        public ?array $captions = null,
        public ?array $platforms = null,
        public ?string $status = null,
        public bool $replaceExtended = false,
    ) {}

    public static function fromRequest(UpdatePostRequest $request): self
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
    public static function fromApiPayload(array $payload, Post $current): self
    {
        return new self(
            title: array_key_exists('title', $payload) ? (string) $payload['title'] : $current->title,
            body: array_key_exists('body', $payload) ? ($payload['body'] !== null ? (string) $payload['body'] : null) : $current->body,
            language: array_key_exists('language', $payload) ? ($payload['language'] !== null ? (string) $payload['language'] : null) : $current->language,
            slug: array_key_exists('slug', $payload) ? ($payload['slug'] !== null ? (string) $payload['slug'] : null) : $current->slug,
            captions: array_key_exists('captions', $payload) ? (is_array($payload['captions']) ? $payload['captions'] : null) : $current->captions,
            platforms: array_key_exists('platforms', $payload) ? (is_array($payload['platforms']) ? $payload['platforms'] : null) : $current->platforms,
            status: array_key_exists('status', $payload) ? (string) $payload['status'] : $current->status,
            replaceExtended: true,
        );
    }
}
