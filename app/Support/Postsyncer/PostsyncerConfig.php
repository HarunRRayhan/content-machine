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
    private const DEFAULT_API_BASE = 'https://postsyncer.com/api/v1';

    private const DEFAULT_UPLOAD_BASE = 'https://upload.postsyncer.com/api/v1';

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
        $base = $this->data['api_base'] ?? null;

        return is_string($base) && $base !== '' ? $base : self::DEFAULT_API_BASE;
    }

    public function uploadBase(): string
    {
        $base = $this->data['upload_base'] ?? null;

        return is_string($base) && $base !== '' ? $base : self::DEFAULT_UPLOAD_BASE;
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

    public function isConfigured(): bool
    {
        $apiKey = $this->apiKey();

        return is_string($apiKey) && $apiKey !== '';
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

            $postsyncer[$key] = $value;
        }

        $settings['postsyncer'] = $postsyncer;
        $workspace->settings = $settings;
        $workspace->save();
    }
}
