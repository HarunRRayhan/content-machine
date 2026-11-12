<?php

namespace App\Support\Socialreins;

use App\Http\Requests\Settings\UpdatePostsyncerSettingsRequest;
use App\Models\Post;
use App\Models\SocialreinsGroup;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Postsyncer\PostPublishPlanner;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Postsyncer\ScriptStudioPostTypes;
use App\Support\Postsyncer\VideoPublishPlanner;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Workspace SocialReins settings under settings.socialreins, plus the per-workspace
 * driver switch at settings.publish_driver ('postsyncer' by default) and, under
 * settings.socialreins.routed_languages, the languages that switch applies to.
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

    /**
     * The driver one subject publishes through. A subject already on a driver stays there
     * whatever the settings say now; a new one follows the workspace driver, limited to
     * subjects whose every language is routed (no routed list means every language) and
     * whose every platform SocialReins covers. A post or video covered only in part splits
     * (see splitOf()) and starts on PostSyncer.
     *
     * @param  array<string, mixed>  $options  the publish options, when deciding a publish
     */
    public static function routesToSocialreins(Post|Video $subject, Workspace $workspace, array $options = []): bool
    {
        if (self::isOnSocialreins($subject)) {
            return true;
        }

        $split = self::newSubjectSplit($subject, $workspace, $options);

        return $split !== null && $split['postsyncer'] === [];
    }

    /**
     * A new post or video whose platforms SocialReins covers only in part: PostSyncer publishes
     * the `postsyncer` platforms first, then the `socialreins` ones go through SocialReins
     * (ContinueSplitOnSocialreinsAction). Null when it goes whole to one driver.
     *
     * @param  array<string, mixed>  $options
     * @return array{socialreins: list<string>, postsyncer: list<string>}|null
     */
    public static function splitOf(Post|Video $subject, Workspace $workspace, array $options = []): ?array
    {
        if (self::isOnSocialreins($subject)) {
            return null;
        }

        $split = self::newSubjectSplit($subject, $workspace, $options);

        return $split !== null && $split['socialreins'] !== [] && $split['postsyncer'] !== [] ? $split : null;
    }

    /**
     * Null when a subject not yet on SocialReins stays on PostSyncer for a reason other than
     * its platforms; otherwise which of its platforms SocialReins covers.
     *
     * @param  array<string, mixed>  $options
     * @return array{socialreins: list<string>, postsyncer: list<string>}|null
     */
    private static function newSubjectSplit(Post|Video $subject, Workspace $workspace, array $options): ?array
    {
        if (self::isOnPostsyncer($subject) || ! self::usesSocialreins($workspace)) {
            return null;
        }

        $config = self::fromWorkspace($workspace);
        $routed = $config->routedLanguages();

        if ($routed !== []) {
            $languages = $subject instanceof Video
                ? app(VideoPublishPlanner::class)->knownLanguages($subject)
                : app(PostPublishPlanner::class)->knownLanguages($subject);

            if ($languages === [] || array_diff($languages, $routed) !== []) {
                return null;
            }
        }

        return $config->platformSplit($subject, $workspace, $options);
    }

    /**
     * Splits the (language, platform) pairs a subject would publish. A platform goes to
     * SocialReins only when every language it is published in covers it, because one set of
     * publish options picks the platforms for all languages.
     *
     * @param  array<string, mixed>  $options
     * @return array{socialreins: list<string>, postsyncer: list<string>}
     */
    private function platformSplit(Post|Video $subject, Workspace $workspace, array $options): array
    {
        // No language lists its platforms: SocialReins takes everything, as before the list existed.
        $lists = array_map(fn (string $language): ?array => $this->coveredPlatforms($language), PostsyncerConfig::LANGUAGES);
        if (array_filter($lists, fn (?array $list): bool => $list !== null) === []) {
            return ['socialreins' => [], 'postsyncer' => []];
        }

        $pairs = $subject instanceof Video
            ? app(VideoPublishPlanner::class)->candidatePairs($subject, $this->planningConfig($workspace), $options)
            : app(PostPublishPlanner::class)->candidatePairs($subject, $options);

        $covered = [];
        foreach ($pairs as [$language, $platform]) {
            $list = $this->coveredPlatforms($language);
            $covered[$platform] = ($covered[$platform] ?? true) && ($list === null || in_array($platform, $list, true));
        }

        return [
            'socialreins' => array_keys(array_filter($covered)),
            'postsyncer' => array_keys(array_diff_key($covered, array_filter($covered))),
        ];
    }

    /** A SocialReins run started and was not fully taken down, or a group may exist there. */
    private static function isOnSocialreins(Post|Video $subject): bool
    {
        if (($subject->publish_progress['driver'] ?? null) === self::DRIVER) {
            return true;
        }

        $rows = SocialreinsResults::rowsOf($subject);
        $states = array_column(SocialreinsResults::byPlatform($rows, requireComplete: false), 'state');

        return array_diff($states, [SocialreinsResults::DELETED]) !== []
            || array_filter($rows, fn (SocialreinsGroup $row): bool => $row->state === SocialreinsGroup::PENDING) !== [];
    }

    /** PostSyncer has groups for it, or a PostSyncer run wrote its progress (no `driver` key). */
    private static function isOnPostsyncer(Post|Video $subject): bool
    {
        foreach ($subject->postsyncer['groups'] ?? [] as $group) {
            if (is_array($group) && filled($group['post_id'] ?? null)) {
                return true;
            }
        }

        return is_array($subject->publish_progress);
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

    /**
     * The CM platforms this language's set has accounts for. Null (never saved) means every
     * platform, which keeps workspaces from before the list on SocialReins for everything.
     *
     * @return list<string>|null
     */
    public function coveredPlatforms(string $language): ?array
    {
        $platforms = $this->languageData($language)['platforms'] ?? null;

        return is_array($platforms) ? array_values(array_intersect(UpdatePostsyncerSettingsRequest::PLATFORMS, $platforms)) : null;
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

    /** @return list<string> languages limited to SocialReins; empty means every language */
    public function routedLanguages(): array
    {
        $languages = $this->data['routed_languages'] ?? [];

        return is_array($languages) ? array_values(array_intersect(PostsyncerConfig::LANGUAGES, $languages)) : [];
    }

    /** With a routed list, one routed language with a set is enough: the others may have none yet. */
    public function isReadyForPublish(): bool
    {
        $routed = $this->routedLanguages();

        return $routed === []
            ? in_array($this->defaultLanguage(), $this->configuredLanguages(), true)
            : array_intersect($routed, $this->configuredLanguages()) !== [];
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

        if (array_key_exists('routed_languages', $input)) {
            $current['routed_languages'] = array_values(array_intersect(PostsyncerConfig::LANGUAGES, (array) $input['routed_languages']));
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
            if (is_array($values['platforms'] ?? null)) {
                $entry['platforms'] = array_values(array_intersect(UpdatePostsyncerSettingsRequest::PLATFORMS, $values['platforms']));
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
