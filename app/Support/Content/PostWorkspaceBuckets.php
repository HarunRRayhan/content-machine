<?php

namespace App\Support\Content;

use App\Models\Post;

/**
 * Derives Bangla/English PostSyncer workspace chips for the posts index and
 * overview when postsyncer.groups is not populated yet (draft/ready posts).
 */
final class PostWorkspaceBuckets
{
    /**
     * @return list<array{key: string, platforms: list<string>}>
     */
    public function forPost(Post $post): array
    {
        $fromGroups = $this->fromPostsyncerGroups($post);

        if ($fromGroups !== []) {
            return $fromGroups;
        }

        $fromCaptions = $this->fromCaptions($post);

        if ($fromCaptions !== []) {
            return $fromCaptions;
        }

        return $this->fallback($post);
    }

    /**
     * @return list<array{key: string, platforms: list<string>}>
     */
    private function fromPostsyncerGroups(Post $post): array
    {
        $postsyncer = $post->postsyncer;
        $groups = is_array($postsyncer) ? ($postsyncer['groups'] ?? null) : null;

        if (! is_array($groups) || $groups === []) {
            return [];
        }

        $byKey = [
            'bn' => [],
            'en' => [],
            'unk' => [],
        ];

        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $key = $this->groupLanguageKey($group);

            foreach ($group['platforms'] ?? [] as $platform) {
                if (! is_string($platform)) {
                    continue;
                }

                $normalized = strtolower(trim($platform));

                if ($normalized === '' || in_array($normalized, $byKey[$key], true)) {
                    continue;
                }

                $byKey[$key][] = $normalized;
            }
        }

        return $this->orderedBuckets($byKey);
    }

    /**
     * @return list<array{key: string, platforms: list<string>}>
     */
    private function fromCaptions(Post $post): array
    {
        $groups = NormalizeCaptions::forDashboard($post->captions);

        if ($groups === []) {
            return [];
        }

        $byKey = [
            'bn' => [],
            'en' => [],
            'unk' => [],
        ];

        foreach ($groups as $group) {
            $key = $this->captionLanguageKey(
                is_string($group['lang'] ?? null) ? $group['lang'] : null,
                is_string($group['part'] ?? null) ? $group['part'] : null,
            );

            foreach ($group['platforms'] as $platform) {
                $name = strtolower(trim($platform['name']));

                if ($name === '' || $name === 'general') {
                    continue;
                }

                if (! $this->platformHasContent($platform)) {
                    continue;
                }

                if (in_array($name, $byKey[$key], true)) {
                    continue;
                }

                $byKey[$key][] = $name;
            }
        }

        return $this->orderedBuckets($byKey);
    }

    /**
     * @return list<array{key: string, platforms: list<string>}>
     */
    private function fallback(Post $post): array
    {
        $platforms = array_values(array_filter(array_map(
            fn (mixed $platform): string => is_string($platform) ? strtolower(trim($platform)) : '',
            $post->platforms ?? [],
        ), fn (string $platform): bool => $platform !== ''));

        if ($platforms === [] && ! filled($post->language)) {
            return [];
        }

        $language = strtolower(trim((string) ($post->language ?? '')));

        if ($language === 'both') {
            return [
                ['key' => 'bn', 'groups' => [], 'platforms' => $platforms],
                ['key' => 'en', 'groups' => [], 'platforms' => $platforms],
            ];
        }

        $key = $language === 'en' || $language === 'english' ? 'en' : 'bn';

        return [
            ['key' => $key, 'groups' => [], 'platforms' => $platforms],
        ];
    }

    /**
     * @param  array<string, list<string>>  $byKey
     * @return list<array{key: string, platforms: list<string>}>
     */
    private function orderedBuckets(array $byKey): array
    {
        $buckets = [];

        foreach (['en', 'bn', 'unk'] as $key) {
            if (($byKey[$key] ?? []) === []) {
                continue;
            }

            $buckets[] = [
                'key' => $key,
                'groups' => [],
                'platforms' => $byKey[$key],
            ];
        }

        return $buckets;
    }

    /**
     * @param  array<string, mixed>  $group
     */
    private function groupLanguageKey(array $group): string
    {
        $raw = strtolower(trim((string) ($group['lang'] ?? $group['language'] ?? '')));

        if (in_array($raw, ['bn', 'bangla', 'bengali', 'বাংলা'], true)) {
            return 'bn';
        }

        if (in_array($raw, ['en', 'english', 'ইংরেজি'], true)) {
            return 'en';
        }

        return 'unk';
    }

    private function captionLanguageKey(?string $lang, ?string $part): string
    {
        if ($lang === 'en') {
            return 'en';
        }

        if ($lang === 'bn') {
            return 'bn';
        }

        if ($part === null || trim($part) === '') {
            return 'unk';
        }

        $lower = strtolower(trim($part));

        if (str_contains($lower, 'bangla') || str_contains($lower, 'bengali') || in_array($lower, ['bn', 'বাংলা'], true)) {
            return 'bn';
        }

        if (str_contains($lower, 'english') || in_array($lower, ['en', 'ইংরেজি'], true)) {
            return 'en';
        }

        return 'unk';
    }

    /**
     * @param  array<string, mixed>  $platform
     */
    private function platformHasContent(array $platform): bool
    {
        if (trim((string) ($platform['caption'] ?? '')) !== '') {
            return true;
        }

        if (trim((string) ($platform['title'] ?? '')) !== '') {
            return true;
        }

        $thread = $platform['thread'] ?? [];

        if (! is_array($thread)) {
            return false;
        }

        foreach ($thread as $segment) {
            if (is_string($segment) && trim($segment) !== '') {
                return true;
            }
        }

        return false;
    }
}
