<?php

namespace App\Support\Content;

/**
 * Turns the several caption JSON shapes we store into one Studio-like list
 * the dashboard can render: [{ part, platforms: [{ name, title, caption, ... }] }].
 */
final class NormalizeCaptions
{
    /**
     * @param  array<mixed>|null  $captions
     * @return list<array{part: string|null, lang: string|null, platforms: list<array{name: string, title: string, caption: string, first_comment: string, images?: list<string>, thread: list<mixed>}>}>
     */
    public static function forDashboard(?array $captions): array
    {
        if ($captions === null || $captions === []) {
            return [];
        }

        // Already Studio-shaped: [{ part, platforms: [...] }]
        if (array_is_list($captions) && isset($captions[0]) && is_array($captions[0]) && array_key_exists('platforms', $captions[0])) {
            return array_map(
                fn (mixed $group): array => self::normalizeGroup(is_array($group) ? $group : []),
                $captions,
            );
        }

        // Import shape: { "main"|"Part 1": { "tiktok": { title, caption, ... }, ... } }
        $groups = [];
        foreach ($captions as $part => $platforms) {
            if (! is_array($platforms)) {
                continue;
            }

            // Nested list of platforms already?
            if (array_is_list($platforms) && isset($platforms[0]) && is_array($platforms[0]) && array_key_exists('name', $platforms[0])) {
                $groups[] = self::normalizeGroup([
                    'part' => is_string($part) && ! in_array(strtolower($part), ['main', 'general'], true) ? $part : null,
                    'platforms' => $platforms,
                ]);

                continue;
            }

            $normalizedPlatforms = [];
            foreach ($platforms as $name => $fields) {
                if (! is_array($fields)) {
                    continue;
                }
                $normalizedPlatforms[] = self::normalizePlatform(
                    is_string($name) ? $name : 'General',
                    $fields,
                );
            }

            if ($normalizedPlatforms === []) {
                continue;
            }

            $groups[] = [
                'part' => is_string($part) && ! in_array(strtolower($part), ['main', 'general'], true) ? $part : null,
                'lang' => self::langOf(null, is_string($part) ? $part : null),
                'platforms' => $normalizedPlatforms,
            ];
        }

        return $groups;
    }

    /**
     * @param  array<mixed>  $group
     * @return array{part: string|null, lang: string|null, platforms: list<array{name: string, title: string, caption: string, first_comment: string, images?: list<string>, thread: list<mixed>}>}
     */
    private static function normalizeGroup(array $group): array
    {
        $platforms = [];
        foreach ($group['platforms'] ?? [] as $platform) {
            if (! is_array($platform)) {
                continue;
            }
            $name = (string) ($platform['name'] ?? 'General');
            $platforms[] = self::normalizePlatform($name, $platform);
        }

        $part = $group['part'] ?? null;

        return [
            'part' => is_string($part) && $part !== '' ? $part : null,
            'lang' => self::langOf($group['lang'] ?? null, is_string($part) ? $part : null),
            'platforms' => $platforms,
        ];
    }

    private static function langOf(mixed $explicit, ?string $part): ?string
    {
        if (is_string($explicit) && in_array($explicit, ['bn', 'en'], true)) {
            return $explicit;
        }

        if ($part === null) {
            return null;
        }

        $lower = strtolower($part);
        if (str_contains($lower, 'bangla') || str_contains($lower, 'bengali') || in_array($lower, ['bn', 'বাংলা'], true)) {
            return 'bn';
        }
        if (str_contains($lower, 'english') || in_array($lower, ['en', 'ইংরেজি'], true)) {
            return 'en';
        }

        return null;
    }

    /**
     * @param  array<mixed>  $fields
     * @return array{name: string, title: string, caption: string, first_comment: string, thread: list<mixed>, images?: list<string>}
     */
    private static function normalizePlatform(string $name, array $fields): array
    {
        $thread = $fields['thread'] ?? [];
        if (! is_array($thread)) {
            $thread = [];
        }

        $result = [
            'name' => $name,
            'title' => (string) ($fields['title'] ?? ''),
            'caption' => (string) ($fields['caption'] ?? ''),
            'first_comment' => (string) ($fields['first_comment'] ?? ''),
            'thread' => array_values($thread),
        ];

        // Only set images when the source caption actually had the key. Inventing
        // images: [] for omitted keys made publish treat every platform as
        // text-only (P-59 follow-up / PR #145 regression). Explicit [] means
        // **Images:** none and must stay.
        if (array_key_exists('images', $fields)) {
            $images = $fields['images'];
            if (! is_array($images)) {
                $images = [];
            }
            $result['images'] = array_values(array_map('strval', $images));
        }

        return $result;
    }
}
