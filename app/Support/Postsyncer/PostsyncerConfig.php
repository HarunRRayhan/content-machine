<?php

namespace App\Support\Postsyncer;

use App\Models\Workspace;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Read/write workspace PostSyncer settings stored under settings.postsyncer.
 */
class PostsyncerConfig
{
    public const API_BASE = 'https://postsyncer.com/api/v1';

    public const UPLOAD_BASE = 'https://upload.postsyncer.com/api/v1';

    /** @var list<string> */
    public const LANGUAGES = ['english', 'bangla'];

    public const DEFAULT_LANGUAGE = 'english';

    public const VIDEO_PUBLISH_DISABLED_MESSAGE = 'Video publishing is temporarily disabled until safe retries and reconciliation are available.';

    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(
        private readonly array $data,
    ) {}

    public static function fromWorkspace(Workspace $workspace): self
    {
        $settings = $workspace->settings ?? [];
        $postsyncer = $settings['postsyncer'] ?? [];

        return new self(is_array($postsyncer) ? $postsyncer : []);
    }

    public function apiKey(): ?string
    {
        $encrypted = $this->data['api_key'] ?? null;

        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            return null;
        }
    }

    public function apiBase(): string
    {
        return self::API_BASE;
    }

    public function uploadBase(): string
    {
        return self::UPLOAD_BASE;
    }

    /**
     * @return array{workspace_id: string|null, platforms: array<string, mixed>}
     */
    public function language(string $lang): array
    {
        $languages = $this->data['languages'] ?? [];
        $langConfig = is_array($languages) ? ($languages[$lang] ?? []) : [];
        $langConfig = is_array($langConfig) ? $langConfig : [];

        $workspaceId = $langConfig['workspace_id'] ?? null;
        $platforms = $langConfig['platforms'] ?? [];

        return [
            'workspace_id' => is_string($workspaceId) && $workspaceId !== '' ? $workspaceId : null,
            'platforms' => is_array($platforms) ? $platforms : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function postTypes(): array
    {
        $postTypes = $this->data['post_types'] ?? [];

        return is_array($postTypes) ? $postTypes : [];
    }

    public function publishEnabled(): bool
    {
        return (bool) ($this->data['publish_enabled'] ?? false);
    }

    public function videoPublishEnabled(): bool
    {
        return (bool) ($this->data['video_publish_enabled'] ?? false);
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

    /**
     * @return list<string>
     */
    public function extraLanguages(): array
    {
        $default = $this->defaultLanguage();

        return array_values(array_filter(
            $this->enabledLanguages(),
            fn (string $language): bool => $language !== $default,
        ));
    }

    public function isConfigured(): bool
    {
        $apiKey = $this->apiKey();

        return is_string($apiKey) && $apiKey !== '';
    }

    public function isReadyForPublish(): bool
    {
        if (! $this->publishEnabled() || ! $this->isConfigured()) {
            return false;
        }

        return $this->language($this->defaultLanguage())['workspace_id'] !== null;
    }

    public function isPlatformEnabled(string $language, string $platform): bool
    {
        $entry = $this->language($language)['platforms'][$platform] ?? [];

        if (! is_array($entry) || ! array_key_exists('enabled', $entry)) {
            return true;
        }

        return MapPostsyncerAccounts::enabled($entry, true);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function write(Workspace $workspace, array $input): void
    {
        $settings = $workspace->settings ?? [];
        $existing = $settings['postsyncer'] ?? [];
        $existing = is_array($existing) ? $existing : [];

        $postsyncer = $existing;

        foreach ($input as $key => $value) {
            if ($key === 'api_key') {
                if (is_string($value) && trim($value) !== '') {
                    $postsyncer['api_key'] = Crypt::encryptString($value);
                }

                continue;
            }

            if ($key === 'languages' && is_array($value)) {
                $postsyncer['languages'] = self::mergeLanguages(
                    is_array($postsyncer['languages'] ?? null) ? $postsyncer['languages'] : [],
                    $value,
                );

                continue;
            }

            $postsyncer[$key] = $value;
        }

        $settings['postsyncer'] = $postsyncer;
        $workspace->settings = $settings;
        $workspace->save();
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private static function mergeLanguages(array $existing, array $incoming): array
    {
        $merged = $existing;

        foreach ($incoming as $lang => $langConfig) {
            if (! is_array($langConfig)) {
                continue;
            }

            $current = is_array($merged[$lang] ?? null) ? $merged[$lang] : [];

            if (array_key_exists('workspace_id', $langConfig)) {
                $current['workspace_id'] = $langConfig['workspace_id'];
            }

            if (array_key_exists('platforms', $langConfig) && is_array($langConfig['platforms'])) {
                $currentPlatforms = is_array($current['platforms'] ?? null) ? $current['platforms'] : [];
                $current['platforms'] = array_replace($currentPlatforms, $langConfig['platforms']);
            }

            $merged[$lang] = $current;
        }

        return $merged;
    }
}
