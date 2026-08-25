<?php

namespace App\Support\Postsyncer;

use App\Models\Video;
use App\Support\Content\NormalizeCaptions;
use Carbon\CarbonImmutable;

/**
 * Builds PostSyncer publish groups for a video: one reel group with Drive media URLs.
 */
class VideoPublishPlanner
{
    public function __construct(
        private readonly MediaUrlResolver $mediaUrlResolver,
    ) {}

    /**
     * Whether any selected platform in the publish set is `ask` for reels.
     *
     * @param  array{platforms?: list<string>}  $options
     */
    public function needsConfirmAsk(Video $video, PostsyncerConfig $config, array $options = []): bool
    {
        $language = $this->resolveLanguage($video);
        $selected = $this->selectedPlatforms($options);
        $platformCaptions = $this->captionsForLanguage($video, $language);

        foreach ($selected as $platform) {
            if (! array_key_exists($platform, $platformCaptions)) {
                continue;
            }

            $state = $this->platformState($config, $platform, 'reel', $language);

            if ($state === 'ask') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{when?: string|null, platforms?: list<string>, confirm_ask?: bool}  $options
     * @return list<PublishGroup>
     */
    public function plan(Video $video, PostsyncerConfig $config, array $options): array
    {
        $language = $this->resolveLanguage($video);
        $langConfig = $config->language($language);
        $workspaceId = $langConfig['workspace_id'] ?? '';

        $when = $this->resolveWhen($options['when'] ?? null);
        $confirmAsk = (bool) ($options['confirm_ask'] ?? false);
        $selected = $this->selectedPlatforms($options);
        $platformCaptions = $this->captionsForLanguage($video, $language);

        $media = $this->mediaUrlResolver->forVideo($video);
        $mediaUrls = array_values(array_filter(
            [$media['video'], $media['cover']],
            fn (?string $url): bool => is_string($url) && $url !== '',
        ));

        $included = [];
        foreach ($selected as $platform) {
            if (! array_key_exists($platform, $platformCaptions)) {
                continue;
            }

            $state = $this->platformState($config, $platform, 'reel', $language);

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
            return [];
        }

        $platforms = array_keys($included);
        sort($platforms);

        return [
            new PublishGroup(
                language: $language,
                workspaceId: $workspaceId,
                platforms: $platforms,
                mediaUrls: $mediaUrls,
                captions: $included,
                when: $when,
                publishNow: $when === null,
            ),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function captionsForLanguage(Video $video, string $language): array
    {
        $captions = $video->captions;

        if ($captions === null || $captions === []) {
            return [];
        }

        if ($this->isFlatCaptionMap($captions)) {
            $out = [];
            foreach ($captions as $platform => $value) {
                $text = $this->captionText(is_array($value) ? $value : ['caption' => $value]);
                if ($text !== '') {
                    $out[strtolower($platform)] = $text;
                }
            }

            return $out;
        }

        $out = [];
        foreach (NormalizeCaptions::forDashboard($captions) as $group) {
            if ($this->languageFromPart($group['part']) !== null
                && $this->languageFromPart($group['part']) !== $language) {
                continue;
            }

            foreach ($group['platforms'] as $platform) {
                $name = strtolower($platform['name']);
                $text = $this->captionText($platform);
                if ($text !== '') {
                    $out[$name] = $text;
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $fields
     */
    private function captionText(array $fields): string
    {
        $caption = trim((string) ($fields['caption'] ?? ''));
        if ($caption !== '') {
            return $caption;
        }

        if (count($fields) === 1 && is_string(reset($fields))) {
            return trim((string) reset($fields));
        }

        return '';
    }

    private function resolveLanguage(Video $video): string
    {
        $lang = strtolower((string) $video->language);

        if (in_array($lang, ['en', 'english'], true)) {
            return 'english';
        }

        return 'bangla';
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    private function selectedPlatforms(array $options): array
    {
        $platforms = $options['platforms'] ?? [];

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

    private function resolveWhen(mixed $when): ?CarbonImmutable
    {
        if (! is_string($when) || trim($when) === '') {
            return null;
        }

        return CarbonImmutable::parse($when);
    }
}
