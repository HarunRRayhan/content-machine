<?php

namespace App\Support\Content;

use App\Models\Idea;

/**
 * Shared handling for the Source tab fields (source_links + source_text)
 * on ideas, posts and videos: validation rules, payload normalisation, and
 * inheritance from an idea to the post/video promoted from it.
 */
final class SourceFields
{
    /**
     * @return array<string, list<string>>
     */
    public static function rules(string $prefix = ''): array
    {
        return [
            $prefix.'source_links' => ['sometimes', 'nullable', 'array', 'max:50'],
            $prefix.'source_links.*.url' => ['required', 'string', 'url', 'max:2048'],
            $prefix.'source_links.*.label' => ['nullable', 'string', 'max:255'],
            $prefix.'source_text' => ['sometimes', 'nullable', 'string', 'max:100000'],
        ];
    }

    /**
     * Reduces any input to a clean list of {url, label} or null when empty.
     *
     * @return list<array{url: string, label: string|null}>|null
     */
    public static function normalizeLinks(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $links = [];

        foreach ($raw as $item) {
            if (is_string($item)) {
                $item = ['url' => $item];
            }

            if (! is_array($item) || ! is_string($item['url'] ?? null) || trim($item['url']) === '') {
                continue;
            }

            $label = isset($item['label']) && is_string($item['label']) && trim($item['label']) !== ''
                ? trim($item['label'])
                : null;

            $links[] = ['url' => trim($item['url']), 'label' => $label];
        }

        return $links === [] ? null : $links;
    }

    public static function normalizeText(mixed $raw): ?string
    {
        return is_string($raw) && trim($raw) !== '' ? $raw : null;
    }

    /**
     * An idea's source, ready to copy onto a promoted post/video.
     *
     * @return array{source_links: list<array{url: string, label: string|null}>|null, source_text: string|null}
     */
    public static function inheritFrom(Idea $idea): array
    {
        return [
            'source_links' => self::normalizeLinks($idea->source_links),
            'source_text' => self::normalizeText($idea->source_text),
        ];
    }
}
