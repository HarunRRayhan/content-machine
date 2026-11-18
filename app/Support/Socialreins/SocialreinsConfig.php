<?php

namespace App\Support\Socialreins;

use App\Models\Post;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Publishing\PlanningConfig;
use App\Support\Publishing\PostPublishPlanner;
use App\Support\Publishing\ScriptStudioPostTypes;
use App\Support\Publishing\VideoPublishPlanner;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Workspace SocialReins settings under settings.socialreins. Every post and video publishes
 * through SocialReins.
 */
class SocialreinsConfig
{
    public const DRIVER = 'socialreins';

    public const POSTSYNCER_HISTORY_MESSAGE = 'This was published through PostSyncer, which Content Machine no longer uses. Change or take it down on the platforms directly.';

    public const DEFAULT_BASE_URL = 'https://brain.socialreins.com/v1';

    /** @var list<string> */
    public const PRIVACY_LEVELS = ['SELF_ONLY', 'FOLLOWER_OF_CREATOR', 'MUTUAL_FOLLOW_FRIENDS', 'PUBLIC_TO_EVERYONE'];

    /** @param array<string, mixed> $data */
    private function __construct(private readonly array $data) {}

    /**
     * The (language, platform) pairs a publish would send, as `language:platform`, split by
     * whether that language's set covers the platform (see covers()). The left-out pairs are
     * published nowhere.
     *
     * @param  array<string, mixed>  $options
     * @return array{covered: list<string>, left_out: list<string>}
     */
    public static function coverage(Post|Video $subject, Workspace $workspace, array $options = []): array
    {
        $config = self::fromWorkspace($workspace);
        $pairs = $subject instanceof Video
            ? app(VideoPublishPlanner::class)->candidatePairs($subject, $config->planningConfig($workspace), $options)
            : app(PostPublishPlanner::class)->candidatePairs($subject, $options);

        $coverage = ['covered' => [], 'left_out' => []];
        foreach ($pairs as [$language, $platform]) {
            $coverage[$config->covers($language, $platform) ? 'covered' : 'left_out'][] = "{$language}:{$platform}";
        }

        return $coverage;
    }

    /** Only a saved list covers anything, so nothing is sent to an account that may not exist. */
    public function covers(string $language, string $platform): bool
    {
        return in_array($platform, $this->coveredPlatforms($language) ?? [], true);
    }

    /**
     * PostSyncer holds, or may hold, a post for this subject: a group with a post id that was not
     * deleted, or a PostSyncer run (progress without a driver) that may have created one. Such a
     * subject is history: CM no longer publishes, moves or takes it down.
     */
    public static function hasPostsyncerPost(Post|Video $subject): bool
    {
        foreach ($subject->postsyncer['groups'] ?? [] as $group) {
            if (is_array($group) && filled($group['post_id'] ?? null) && ($group['status'] ?? null) !== 'DELETED') {
                return true;
            }
        }

        $progress = $subject->publish_progress;

        return is_array($progress) && ! isset($progress['driver']) && ! self::runCreatedNothing($progress);
    }

    /**
     * A PostSyncer run that provably created no post: no create with an unknown outcome and no
     * completed group.
     *
     * @param  array<string, mixed>  $progress
     */
    private static function runCreatedNothing(array $progress): bool
    {
        $current = $progress['current'] ?? null;

        return ($progress['state'] ?? null) !== 'uncertain'
            && ($current === null || (is_array($current) && ($current['phase'] ?? null) === 'retryable'))
            && ($progress['completed_groups'] ?? []) === []
            && ($progress['base_groups'] ?? []) === [];
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
     * The CM platforms this language's set has accounts for. Null when never saved.
     *
     * @return list<string>|null
     */
    public function coveredPlatforms(string $language): ?array
    {
        $platforms = $this->languageData($language)['platforms'] ?? null;

        return is_array($platforms) ? array_values(array_intersect(PlanningConfig::PLATFORMS, $platforms)) : null;
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

        return is_string($language) && in_array($language, PlanningConfig::LANGUAGES, true)
            ? $language
            : PlanningConfig::DEFAULT_LANGUAGE;
    }

    /** @return list<string> languages with both a set id and a token */
    public function configuredLanguages(): array
    {
        return array_values(array_filter(
            PlanningConfig::LANGUAGES,
            fn (string $language): bool => $this->setId($language) !== null && $this->token($language) !== null,
        ));
    }

    public function isReadyForPublish(): bool
    {
        return in_array($this->defaultLanguage(), $this->configuredLanguages(), true);
    }

    /**
     * The shape the CM planners read: a language's set id stands in for the planner's
     * workspace id, and the post-type matrix is the workspace's own (SocialReins), else the one
     * saved under settings.postsyncer before PostSyncer was removed, else the Script Studio defaults.
     */
    public function planningConfig(Workspace $workspace): PlanningConfig
    {
        $languages = [];
        foreach ($this->configuredLanguages() as $language) {
            $languages[$language] = ['workspace_id' => $this->setId($language)];
        }

        $postTypes = $this->data['post_types'] ?? null;
        if (! is_array($postTypes) || ScriptStudioPostTypes::isEmpty($postTypes)) {
            $saved = $workspace->settings['postsyncer']['post_types'] ?? null;
            $postTypes = PlanningConfig::fromArray(['post_types' => is_array($saved) ? $saved : []])->postTypes();
        }
        if (ScriptStudioPostTypes::isEmpty($postTypes)) {
            $postTypes = ScriptStudioPostTypes::defaults();
        }

        return PlanningConfig::fromArray([
            'languages' => $languages,
            'enabled_languages' => array_keys($languages),
            'default_language' => $this->defaultLanguage(),
            'post_types' => $postTypes,
        ]);
    }

    /**
     * Blank secrets keep the stored value.
     *
     * @param  array<string, mixed>  $input
     */
    public static function write(Workspace $workspace, array $input): void
    {
        $settings = $workspace->settings ?? [];
        $current = is_array($settings['socialreins'] ?? null) ? $settings['socialreins'] : [];

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
            if (! in_array($language, PlanningConfig::LANGUAGES, true) || ! is_array($values)) {
                continue;
            }

            $entry = is_array($current['languages'][$language] ?? null) ? $current['languages'][$language] : [];
            if (array_key_exists('set_id', $values)) {
                $entry['set_id'] = $values['set_id'];
            }
            if (is_array($values['platforms'] ?? null)) {
                $entry['platforms'] = array_values(array_intersect(PlanningConfig::PLATFORMS, $values['platforms']));
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
