<?php

namespace App\Support\Postsyncer;

use App\Http\Requests\Settings\UpdatePostsyncerSettingsRequest;
use Illuminate\Support\Facades\Cache;

/**
 * Live handles for the Bangla and English PostSyncer workspaces.
 * One GET /accounts covers both; a third workspace on the same
 * account is ignored. Facebook often has no username, so a stored
 * handle is kept when the API omits one.
 */
class PostsyncerHandleDirectory
{
    /**
     * @var array<string, string>
     */
    private const LANG_KEYS = [
        'bangla' => 'bn',
        'english' => 'en',
    ];

    public function __construct(
        private readonly PostsyncerClient $client,
        private readonly PostsyncerConfig $config,
        private readonly string $cacheKey,
    ) {}

    /**
     * @return array{bn: array<string, array{handle: string, name: string}>, en: array<string, array{handle: string, name: string}>}
     */
    public function forPreview(): array
    {
        $stored = $this->storedHandles();
        $accounts = $this->accounts();

        $preview = $stored;

        foreach ($accounts as $account) {
            $workspaceId = (string) ($account['workspace_id'] ?? '');
            $lang = $this->langForWorkspace($workspaceId);

            if ($lang === null) {
                continue;
            }

            $platform = strtolower((string) ($account['platform'] ?? ''));

            if ($platform === '' || ! array_key_exists($platform, $preview[$lang])) {
                continue;
            }

            $username = $account['username'] ?? null;
            $handle = is_string($username) && trim($username) !== ''
                ? ltrim(trim($username), '@')
                : $preview[$lang][$platform]['handle'];

            $preview[$lang][$platform] = [
                'handle' => $handle,
                'name' => $preview[$lang][$platform]['name'],
            ];
        }

        return $preview;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function accounts(): array
    {
        if (! $this->config->isConfigured()) {
            return [];
        }

        try {
            $accounts = Cache::remember(
                'postsyncer.accounts.'.$this->cacheKey,
                now()->addMinutes(10),
                fn (): array => $this->client->listAllAccounts(),
            );
        } catch (PostsyncerException) {
            return [];
        }

        return is_array($accounts) ? $accounts : [];
    }

    /**
     * @return array{bn: array<string, array{handle: string, name: string}>, en: array<string, array{handle: string, name: string}>}
     */
    private function storedHandles(): array
    {
        $preview = [];

        foreach (self::LANG_KEYS as $configLang => $previewLang) {
            $platforms = $this->config->language($configLang)['platforms'];
            $preview[$previewLang] = [];

            foreach (UpdatePostsyncerSettingsRequest::PLATFORMS as $platform) {
                $entry = is_array($platforms[$platform] ?? null) ? $platforms[$platform] : [];
                $handle = is_string($entry['handle'] ?? null) ? ltrim(trim($entry['handle']), '@') : '';

                $preview[$previewLang][$platform] = [
                    'handle' => $handle,
                    'name' => 'Harun R. Rayhan',
                ];
            }
        }

        return $preview;
    }

    private function langForWorkspace(string $workspaceId): ?string
    {
        if ($workspaceId === '') {
            return null;
        }

        foreach (self::LANG_KEYS as $configLang => $previewLang) {
            $configured = $this->config->language($configLang)['workspace_id'];

            if ($configured !== null && $configured === $workspaceId) {
                return $previewLang;
            }
        }

        return null;
    }
}
