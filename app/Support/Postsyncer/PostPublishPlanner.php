<?php

namespace App\Support\Postsyncer;

use App\Models\Post;
use App\Support\Content\NormalizeCaptions;
use Carbon\CarbonImmutable;

/**
 * Builds PostSyncer publish groups for a post. v1: one language group per plan call.
 */
class PostPublishPlanner
{
    public function __construct(
        private readonly MediaUrlResolver $mediaUrlResolver,
    ) {}

    /**
     * @param  array{when?: string|null, platforms?: list<string>, confirm_ask?: bool}  $options
     * @return list<PublishGroup>
     */
    public function plan(Post $post, PostsyncerConfig $config, array $options): array
    {
        $language = $this->resolveLanguage($post);
        $langConfig = $config->language($language);
        $workspaceId = $langConfig['workspace_id'] ?? '';

        $mediaUrls = $this->mediaUrlResolver->forPost($post);
        $postType = $mediaUrls !== [] ? 'photo' : 'text';

        $selected = $this->selectedPlatforms($post, $options);
        $confirmAsk = (bool) ($options['confirm_ask'] ?? false);

        $included = [];
        foreach ($selected as $platform) {
            $state = $this->platformState($config, $platform, $postType, $language);

            if ($state === null || $state === 'off') {
                continue;
            }

            if ($state === 'ask' && ! $confirmAsk) {
                throw new PostsyncerException(
                    "Platform {$platform} requires explicit confirmation (confirm_ask)."
                );
            }

            $included[] = $platform;
        }

        $captions = $this->captionsForPlatforms($post, $included);
        $when = $this->resolveWhen($options['when'] ?? null);

        return [
            new PublishGroup(
                language: $language,
                workspaceId: $workspaceId ?? '',
                platforms: $included,
                mediaUrls: $mediaUrls,
                captions: $captions,
                when: $when,
                publishNow: $when === null,
            ),
        ];
    }

    private function resolveLanguage(Post $post): string
    {
        return strtolower((string) $post->language) === 'en' ? 'english' : 'bangla';
    }

    /**
     * @param  array{platforms?: list<string>}  $options
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
     * @param  list<string>  $platforms
     * @return array<string, string>
     */
    private function captionsForPlatforms(Post $post, array $platforms): array
    {
        $all = $this->extractCaptions($post);
        $out = [];

        foreach ($platforms as $platform) {
            if (array_key_exists($platform, $all)) {
                $out[$platform] = $all[$platform];
            }
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function extractCaptions(Post $post): array
    {
        $captions = $post->captions;

        if ($captions === null || $captions === []) {
            return [];
        }

        if ($this->isFlatCaptionMap($captions)) {
            $out = [];
            foreach ($captions as $platform => $value) {
                if (! is_string($platform)) {
                    continue;
                }
                $text = $this->captionText($value);
                if ($text !== null) {
                    $out[strtolower($platform)] = $text;
                }
            }

            return $out;
        }

        $out = [];
        foreach (NormalizeCaptions::forDashboard($captions) as $group) {
            foreach ($group['platforms'] as $platform) {
                $name = strtolower($platform['name']);
                $text = trim($platform['caption']);
                if ($text !== '') {
                    $out[$name] = $text;
                }
            }
        }

        return $out;
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

    private function captionText(mixed $value): ?string
    {
        if (is_string($value)) {
            $text = trim($value);

            return $text !== '' ? $text : null;
        }

        if (! is_array($value)) {
            return null;
        }

        $text = trim((string) ($value['caption'] ?? ''));

        return $text !== '' ? $text : null;
    }

    private function resolveWhen(mixed $when): ?CarbonImmutable
    {
        if (! is_string($when) || trim($when) === '') {
            return null;
        }

        return CarbonImmutable::parse($when);
    }
}
