<?php

namespace App\Support\Socialreins;

use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Postsyncer\ScriptStudioPostTypes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Workspace SocialReins settings under settings.socialreins, plus the per-workspace
 * driver switch at settings.publish_driver ('postsyncer' by default).
 */
class SocialreinsConfig
{
    public const DRIVER = 'socialreins';

    public const DEFAULT_BASE_URL = 'https://brain.socialreins.com/v1';

    /** @var list<string> */
    public const PRIVACY_LEVELS = ['SELF_ONLY', 'FOLLOWER_OF_CREATOR', 'MUTUAL_FOLLOW_FRIENDS', 'PUBLIC_TO_EVERYONE'];

    /** @param array<string, mixed> $data */
    private function __construct(private readonly array $data) {}

    public static function usesSocialreins(Workspace $workspace): bool
    {
        return ($workspace->settings['publish_driver'] ?? null) === self::DRIVER;
    }

    public static function fromWorkspace(Workspace $workspace): self
    {
        $data = $workspace->settings['socialreins'] ?? [];

        return new self(is_array($data) ? $data : []);
    }

    public function baseUrl(): string
    {
        $url = $this->data['base_url'] ?? null;

        return rtrim(is_string($url) && $url !== '' ? $url : self::DEFAULT_BASE_URL, '/');
    }

    public function webhookSecret(): ?string
    {
        return $this->decrypt($this->data['webhook_secret'] ?? null);
    }

    public function token(string $language): ?string
    {
        return $this->decrypt($this->languageData($language)['token'] ?? null);
    }

    public function setId(string $language): ?string
    {
        $id = $this->languageData($language)['set_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** The TikTok visibility this workspace chose; null means TikTok posts are refused. */
    public function tiktokPrivacy(): ?string
    {
        $level = $this->data['tiktok_privacy_level'] ?? null;

        return is_string($level) && in_array($level, self::PRIVACY_LEVELS, true) ? $level : null;
    }

    public function defaultLanguage(): string
    {
        $language = $this->data['default_language'] ?? null;

        return is_string($language) && in_array($language, PostsyncerConfig::LANGUAGES, true)
            ? $language
            : PostsyncerConfig::DEFAULT_LANGUAGE;
    }

    /** @return list<string> languages with both a set id and a token */
    public function configuredLanguages(): array
    {
        return array_values(array_filter(
            PostsyncerConfig::LANGUAGES,
            fn (string $language): bool => $this->setId($language) !== null && $this->token($language) !== null,
        ));
    }

    public function isReadyForPublish(): bool
    {
        return in_array($this->defaultLanguage(), $this->configuredLanguages(), true);
    }

    /**
     * The shape the CM planners read: a language's set id stands in for the PostSyncer
     * workspace id, and the post-type matrix is the workspace's own (SocialReins) or its
     * PostSyncer one, else the Script Studio defaults. No PostSyncer key is needed.
     */
    public function planningConfig(Workspace $workspace): PostsyncerConfig
    {
        $languages = [];
        foreach ($this->configuredLanguages() as $language) {
            $languages[$language] = ['workspace_id' => $this->setId($language), 'platforms' => []];
        }

        $postTypes = $this->data['post_types'] ?? null;
        if (! is_array($postTypes) || ScriptStudioPostTypes::isEmpty($postTypes)) {
            $postTypes = PostsyncerConfig::fromWorkspace($workspace)->postTypes();
        }
        if (ScriptStudioPostTypes::isEmpty($postTypes)) {
            $postTypes = ScriptStudioPostTypes::defaults();
        }

        return PostsyncerConfig::fromArray([
            'languages' => $languages,
            'enabled_languages' => array_keys($languages),
            'default_language' => $this->defaultLanguage(),
            'post_types' => $postTypes,
        ]);
    }

    /**
     * Blank secrets keep the stored value; `publish_driver` is stored beside the
     * socialreins block so switching it never rewrites the PostSyncer settings.
     *
     * @param  array<string, mixed>  $input
     */
    public static function write(Workspace $workspace, array $input): void
    {
        $settings = $workspace->settings ?? [];
        $current = is_array($settings['socialreins'] ?? null) ? $settings['socialreins'] : [];

        if (array_key_exists('publish_driver', $input)) {
            $settings['publish_driver'] = $input['publish_driver'] === self::DRIVER ? self::DRIVER : 'postsyncer';
        }

        // Tokens and the webhook secret belong to the host they were issued for: pointing the
        // workspace somewhere else must not send them there, so they are entered again.
        if (is_string($input['base_url'] ?? null)
            && rtrim(trim($input['base_url']), '/') !== (new self($current))->baseUrl()) {
            unset($current['webhook_secret']);
            foreach (array_keys((array) ($current['languages'] ?? [])) as $language) {
                unset($current['languages'][$language]['token']);
            }
        }

        if (is_string($input['base_url'] ?? null)) {
            $input['base_url'] = rtrim(trim($input['base_url']), '/');
        }

        foreach (['base_url', 'tiktok_privacy_level', 'default_language'] as $key) {
            if (array_key_exists($key, $input)) {
                $current[$key] = $input[$key];
            }
        }

        if (is_string($input['webhook_secret'] ?? null) && trim($input['webhook_secret']) !== '') {
            $current['webhook_secret'] = Crypt::encryptString(trim($input['webhook_secret']));
        }

        foreach ((array) ($input['languages'] ?? []) as $language => $values) {
            if (! in_array($language, PostsyncerConfig::LANGUAGES, true) || ! is_array($values)) {
                continue;
            }

            $entry = is_array($current['languages'][$language] ?? null) ? $current['languages'][$language] : [];
            if (array_key_exists('set_id', $values)) {
                $entry['set_id'] = $values['set_id'];
            }
            if (is_string($values['token'] ?? null) && trim($values['token']) !== '') {
                $entry['token'] = Crypt::encryptString(trim($values['token']));
            }
            $current['languages'][$language] = $entry;
        }

        $settings['socialreins'] = $current;
        $workspace->settings = $settings;
        $workspace->save();
    }

    /** @return array<string, mixed> */
    private function languageData(string $language): array
    {
        $entry = $this->data['languages'][$language] ?? [];

        return is_array($entry) ? $entry : [];
    }

    private function decrypt(mixed $encrypted): ?string
    {
        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            return null;
        }
    }
}
