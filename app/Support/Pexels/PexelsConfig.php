<?php

namespace App\Support\Pexels;

use App\Models\Workspace;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

final class PexelsConfig
{
    /** @param array<string, mixed> $data */
    private function __construct(private readonly array $data) {}

    public static function fromWorkspace(Workspace $workspace): self
    {
        $settings = $workspace->settings ?? [];
        $pexels = $settings['pexels'] ?? [];

        return new self(is_array($pexels) ? $pexels : []);
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

    public function isConfigured(): bool
    {
        return filled($this->apiKey());
    }

    public static function write(Workspace $workspace, string $apiKey): void
    {
        $settings = $workspace->settings ?? [];
        $pexels = is_array($settings['pexels'] ?? null) ? $settings['pexels'] : [];
        $pexels['api_key'] = Crypt::encryptString(trim($apiKey));
        $settings['pexels'] = $pexels;
        $workspace->settings = $settings;
        $workspace->save();
    }

    public static function disconnect(Workspace $workspace): void
    {
        $settings = $workspace->settings ?? [];
        unset($settings['pexels']);
        $workspace->settings = $settings;
        $workspace->save();
    }
}
