<?php

namespace App\Support\Publishing;

/**
 * What the publish planners read: a set id per language and the post-type matrix.
 * SocialreinsConfig::planningConfig() builds it.
 */
class PlanningConfig
{
    /** @var list<string> */
    public const LANGUAGES = ['english', 'bangla'];

    public const DEFAULT_LANGUAGE = 'english';

    /** @var list<string> */
    public const PLATFORMS = [
        'facebook',
        'instagram',
        'twitter',
        'threads',
        'bluesky',
        'tiktok',
        'linkedin',
        'youtube',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(
        private readonly array $data,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /**
     * @return array{workspace_id: string|null}
     */
    public function language(string $lang): array
    {
        $languages = $this->data['languages'] ?? [];
        $langConfig = is_array($languages) ? ($languages[$lang] ?? []) : [];
        $workspaceId = is_array($langConfig) ? ($langConfig['workspace_id'] ?? null) : null;

        return [
            'workspace_id' => is_string($workspaceId) && $workspaceId !== '' ? $workspaceId : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function postTypes(): array
    {
        $postTypes = $this->data['post_types'] ?? [];
        $postTypes = is_array($postTypes) ? $postTypes : [];

        // Matrices saved before YouTube image posts existed have no youtube photo/carousel
        // cell, and the planner skips a null cell silently. Fill only unset (missing or null)
        // cells from the bundled matrix; any explicit stored state wins.
        // shortcut: only youtube photo/carousel are filled; generalize if more cells get added later.
        if (! ScriptStudioPostTypes::isEmpty($postTypes)) {
            $platforms = is_array($postTypes['platforms'] ?? null) ? $postTypes['platforms'] : [];
            $youtube = is_array($platforms['youtube'] ?? null) ? $platforms['youtube'] : [];

            foreach (['photo', 'carousel'] as $postType) {
                if (($youtube[$postType] ?? null) === null) {
                    $youtube[$postType] = ScriptStudioPostTypes::defaults()['platforms']['youtube'][$postType] ?? null;
                }
            }

            $platforms['youtube'] = $youtube;
            $postTypes['platforms'] = $platforms;
        }

        return $postTypes;
    }

    public function defaultLanguage(): string
    {
        $language = $this->data['default_language'] ?? null;

        return is_string($language) && in_array($language, self::LANGUAGES, true)
            ? $language
            : self::DEFAULT_LANGUAGE;
    }

    /**
     * @return list<string>
     */
    public function enabledLanguages(): array
    {
        $enabled = $this->data['enabled_languages'] ?? null;
        $languages = [];

        if (is_array($enabled)) {
            foreach ($enabled as $language) {
                if (is_string($language) && in_array($language, self::LANGUAGES, true)) {
                    $languages[] = $language;
                }
            }
        }

        if ($languages === []) {
            foreach (self::LANGUAGES as $language) {
                if ($this->language($language)['workspace_id'] !== null) {
                    $languages[] = $language;
                }
            }
        }

        $default = $this->defaultLanguage();

        if (! in_array($default, $languages, true)) {
            array_unshift($languages, $default);
        }

        return array_values(array_unique($languages));
    }
}
