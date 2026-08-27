<?php

namespace App\Support\Postsyncer;

use App\Models\Post;
use App\Support\Content\NormalizeCaptions;
use Carbon\CarbonImmutable;

/**
 * Builds PostSyncer publish groups for a post: language workspaces, Twitter thread
 * isolation, and media-set / first-comment splits.
 */
class PostPublishPlanner
{
    public function __construct(
        private readonly MediaUrlResolver $mediaUrlResolver,
    ) {}

    /**
     * Whether any selected platform in the publish set is `ask` for its resolved post type.
     *
     * @param  array{platforms?: list<string>}  $options
     */
    public function needsConfirmAsk(Post $post, PostsyncerConfig $config, array $options = []): bool
    {
        $selected = $this->selectedPlatforms($post, $options);
        $defaultMediaUrls = $this->mediaUrlResolver->forPost($post);
        $byLanguage = $this->captionsByLanguage($post);

        foreach ($byLanguage as $language => $platformCaptions) {
            foreach ($selected as $platform) {
                if (! array_key_exists($platform, $platformCaptions)) {
                    continue;
                }

                $mediaUrls = $this->resolveMediaUrls($post, $platformCaptions[$platform], $defaultMediaUrls);
                $postType = $mediaUrls !== [] ? 'photo' : 'text';
                $state = $this->platformState($config, $platform, $postType, $language);

                if ($state === 'ask') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array{when?: string|null, platforms?: list<string>, confirm_ask?: bool}  $options
     * @return list<PublishGroup>
     */
    public function plan(Post $post, PostsyncerConfig $config, array $options): array
    {
        $when = $this->resolveWhen($options['when'] ?? null, $this->workspaceTimezone($post));
        $confirmAsk = (bool) ($options['confirm_ask'] ?? false);
        $selected = $this->selectedPlatforms($post, $options);
        $defaultMediaUrls = $this->mediaUrlResolver->forPost($post);
        $byLanguage = $this->captionsByLanguage($post);

        $groups = [];
        foreach ($byLanguage as $language => $platformCaptions) {
            $langConfig = $config->language($language);
            $workspaceId = $langConfig['workspace_id'] ?? '';

            if ($workspaceId === '') {
                continue;
            }

            $included = [];
            foreach ($selected as $platform) {
                if (! array_key_exists($platform, $platformCaptions)) {
                    continue;
                }

                if (! $config->isPlatformEnabled($language, $platform)) {
                    continue;
                }

                $mediaUrls = $this->resolveMediaUrls($post, $platformCaptions[$platform], $defaultMediaUrls);
                $postType = $mediaUrls !== [] ? 'photo' : 'text';
                $state = $this->platformState($config, $platform, $postType, $language);

                if ($state === null || $state === 'off') {
                    continue;
                }

                if ($state === 'ask' && ! $confirmAsk) {
                    throw new PostsyncerException(
                        "Platform {$platform} requires explicit confirmation (confirm_ask)."
                    );
                }

                $included[$platform] = $platformCaptions[$platform];
            }

            if ($included === []) {
                continue;
            }

            $groups = array_merge(
                $groups,
                $this->splitLanguageGroups($post, $language, $workspaceId, $included, $defaultMediaUrls, $when),
            );
        }

        return $groups;
    }

    /**
     * @param  array<string, array{caption: string, first_comment: string, images?: list<string>, thread: list<string>}>  $platformCaptions
     * @param  list<string>  $defaultMediaUrls
     * @return list<PublishGroup>
     */
    private function splitLanguageGroups(
        Post $post,
        string $language,
        int|string $workspaceId,
        array $platformCaptions,
        array $defaultMediaUrls,
        ?CarbonImmutable $when,
    ): array {
        $remaining = $platformCaptions;
        $groups = [];

        if (isset($remaining['twitter']) && $remaining['twitter']['thread'] !== []) {
            $twitter = $remaining['twitter'];
            unset($remaining['twitter']);

            $threadTweets = array_values(array_filter(
                [$twitter['caption'], ...$twitter['thread']],
                fn (string $segment): bool => trim($segment) !== '',
            ));

            $groups[] = new PublishGroup(
                language: $language,
                workspaceId: $workspaceId,
                platforms: ['twitter'],
                mediaUrls: $this->resolveMediaUrls($post, $twitter, $defaultMediaUrls),
                captions: ['twitter' => $twitter['caption']],
                when: $when,
                publishNow: $when === null,
                threadTweets: $threadTweets,
            );
        }

        $buckets = [];
        foreach ($remaining as $platform => $data) {
            $mediaUrls = $this->resolveMediaUrls($post, $data, $defaultMediaUrls);
            $key = implode("\0", $mediaUrls).'|'.$data['first_comment'];
            $buckets[$key]['mediaUrls'] = $mediaUrls;
            $buckets[$key]['platforms'][$platform] = $data['caption'];
        }

        foreach ($buckets as $bucket) {
            $platforms = array_keys($bucket['platforms']);
            sort($platforms);

            $groups[] = new PublishGroup(
                language: $language,
                workspaceId: $workspaceId,
                platforms: $platforms,
                mediaUrls: $bucket['mediaUrls'],
                captions: $bucket['platforms'],
                when: $when,
                publishNow: $when === null,
            );
        }

        return $groups;
    }

    /**
     * @param  array{caption: string, first_comment: string, images?: list<string>, thread: list<string>}  $platformData
     * @param  list<string>  $defaultMediaUrls
     * @return list<string>
     */
    private function resolveMediaUrls(Post $post, array $platformData, array $defaultMediaUrls): array
    {
        if (array_key_exists('images', $platformData)) {
            return $this->mediaUrlResolver->resolveNamedImages($post, $platformData['images']);
        }

        return $defaultMediaUrls;
    }

    /**
     * @return array<string, array<string, array{caption: string, first_comment: string, images?: list<string>, thread: list<string>}>>
     */
    private function captionsByLanguage(Post $post): array
    {
        $captions = $post->captions;

        if ($captions === null || $captions === []) {
            return [];
        }

        if ($this->isFlatCaptionMap($captions)) {
            $language = $this->resolveLanguage($post);
            $out = [];
            foreach ($captions as $platform => $value) {
                $normalized = $this->normalizePlatformFields(is_array($value) ? $value : ['caption' => $value]);
                if ($normalized['caption'] !== '') {
                    $out[strtolower($platform)] = $normalized;
                }
            }

            return $out === [] ? [] : [$language => $out];
        }

        $byLanguage = [];
        foreach (NormalizeCaptions::forDashboard($captions) as $group) {
            $language = $this->languageFromPart($group['part']) ?? $this->resolveLanguage($post);
            foreach ($group['platforms'] as $platform) {
                $name = strtolower($platform['name']);
                $normalized = $this->normalizePlatformFields($platform, fromNormalizedCaptions: true);
                if ($normalized['caption'] !== '') {
                    $byLanguage[$language][$name] = $normalized;
                }
            }
        }

        return $byLanguage;
    }

    /**
     * @param  array<mixed>  $fields
     * @return array{caption: string, first_comment: string, images?: list<string>, thread: list<string>}
     */
    private function normalizePlatformFields(array $fields, bool $fromNormalizedCaptions = false): array
    {
        $caption = trim((string) ($fields['caption'] ?? ''));

        if ($caption === '' && ! array_key_exists('caption', $fields) && count($fields) === 1) {
            $caption = trim((string) reset($fields));
        }

        $thread = $fields['thread'] ?? [];
        if (! is_array($thread)) {
            $thread = [];
        }

        $normalized = [
            'caption' => $caption,
            'first_comment' => trim((string) ($fields['first_comment'] ?? '')),
            'thread' => array_values(array_map(
                fn (mixed $segment): string => is_string($segment) ? trim($segment) : '',
                $thread,
            )),
        ];

        if (array_key_exists('images', $fields)) {
            $raw = $fields['images'];
            $images = is_array($raw) ? array_values(array_map('strval', $raw)) : [];
            if ($images !== [] || ! $fromNormalizedCaptions) {
                $normalized['images'] = $images;
            }
        }

        return $normalized;
    }

    private function languageFromPart(?string $part): ?string
    {
        if ($part === null || trim($part) === '') {
            return null;
        }

        $p = strtolower(trim($part));
        if (str_contains($p, 'bangla') || str_contains($p, 'bengali') || $p === 'bn' || $p === 'বাংলা') {
            return 'bangla';
        }

        if (str_contains($p, 'english') || $p === 'en' || $p === 'ইংরেজি') {
            return 'english';
        }

        return null;
    }

    private function resolveLanguage(Post $post): string
    {
        $lang = strtolower((string) $post->language);

        if (in_array($lang, ['en', 'english'], true)) {
            return 'english';
        }

        return 'bangla';
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    private function selectedPlatforms(Post $post, array $options): array
    {
        $platforms = $options['platforms'] ?? $post->platforms ?? [];

        if (! is_array($platforms)) {
            return [];
        }

        return array_values(array_map(
            fn (mixed $platform): string => strtolower((string) $platform),
            $platforms,
        ));
    }

    private function platformState(
        PostsyncerConfig $config,
        string $platform,
        string $postType,
        string $language,
    ): ?string {
        $matrix = $config->postTypes();
        $platforms = is_array($matrix['platforms'] ?? null) ? $matrix['platforms'] : [];
        $base = is_array($platforms[$platform] ?? null) ? $platforms[$platform] : [];
        $state = $base[$postType] ?? null;

        $overrides = is_array($matrix['overrides'] ?? null) ? $matrix['overrides'] : [];
        $langOverrides = is_array($overrides[$language] ?? null) ? $overrides[$language] : [];
        $platformOverrides = is_array($langOverrides[$platform] ?? null) ? $langOverrides[$platform] : [];

        if (array_key_exists($postType, $platformOverrides)) {
            $state = $platformOverrides[$postType];
        }

        return is_string($state) ? $state : null;
    }

    /**
     * @param  array<mixed>  $captions
     */
    private function isFlatCaptionMap(array $captions): bool
    {
        foreach ($captions as $key => $value) {
            if (is_int($key)) {
                return false;
            }

            if (is_array($value) && (array_key_exists('caption', $value) || array_key_exists('title', $value))) {
                return true;
            }

            if (is_string($value)) {
                return true;
            }
        }

        return false;
    }

    private function workspaceTimezone(Post $post): string
    {
        $timezone = $post->workspace?->timezone;

        return is_string($timezone) && trim($timezone) !== '' ? $timezone : 'Asia/Dhaka';
    }

    private function resolveWhen(mixed $when, string $timezone): ?CarbonImmutable
    {
        if (! is_string($when) || trim($when) === '') {
            return null;
        }

        return CarbonImmutable::parse($when, $timezone)->timezone($timezone);
    }
}
