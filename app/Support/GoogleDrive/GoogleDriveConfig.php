<?php

namespace App\Support\GoogleDrive;

use App\Models\Workspace;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Read/write workspace Google Drive settings. Tokens are encrypted before
 * they are stored in the workspace JSON column.
 */
final class GoogleDriveConfig
{
    public const SCOPE = 'https://www.googleapis.com/auth/drive';

    /** @param array<string, mixed> $data */
    private function __construct(private readonly array $data) {}

    public static function fromWorkspace(Workspace $workspace): self
    {
        $settings = $workspace->settings ?? [];
        $drive = $settings['google_drive'] ?? [];

        return new self(is_array($drive) ? $drive : []);
    }

    public static function clientConfigured(): bool
    {
        $clientId = config('services.google_drive.client_id');
        $clientSecret = config('services.google_drive.client_secret');

        return is_string($clientId)
            && trim($clientId) !== ''
            && is_string($clientSecret)
            && trim($clientSecret) !== '';
    }

    public static function redirectUri(): string
    {
        $redirect = config('services.google_drive.redirect');

        if (is_string($redirect) && trim($redirect) !== '') {
            return $redirect;
        }

        return rtrim((string) config('app.url'), '/').'/settings/google-drive/callback';
    }

    public function isConnected(): bool
    {
        return $this->refreshToken() !== null;
    }

    public function connectedEmail(): ?string
    {
        $email = $this->data['email'] ?? null;

        return is_string($email) && $email !== '' ? $email : null;
    }

    public function folderId(): ?string
    {
        $folderId = $this->data['folder_id'] ?? null;

        return is_string($folderId) && $folderId !== '' ? $folderId : null;
    }

    public function folderName(): ?string
    {
        $folderName = $this->data['folder_name'] ?? null;

        return is_string($folderName) && $folderName !== '' ? $folderName : null;
    }

    public function accessToken(): ?string
    {
        return $this->decrypt('access_token');
    }

    public function refreshToken(): ?string
    {
        return $this->decrypt('refresh_token');
    }

    public function accessTokenExpiresAt(): int
    {
        $expiresAt = $this->data['access_token_expires_at'] ?? 0;

        return is_numeric($expiresAt) ? (int) $expiresAt : 0;
    }

    /**
     * @param  array{access_token: string, expires_at: int, refresh_token?: string|null, email?: string|null}  $tokens
     */
    public static function storeTokens(Workspace $workspace, array $tokens): void
    {
        $drive = self::dataFor($workspace);
        $drive['access_token'] = Crypt::encryptString($tokens['access_token']);
        $drive['access_token_expires_at'] = $tokens['expires_at'];

        if (array_key_exists('refresh_token', $tokens) && is_string($tokens['refresh_token']) && $tokens['refresh_token'] !== '') {
            $drive['refresh_token'] = Crypt::encryptString($tokens['refresh_token']);
        }

        if (array_key_exists('email', $tokens) && is_string($tokens['email']) && $tokens['email'] !== '') {
            $drive['email'] = $tokens['email'];
        }

        self::write($workspace, $drive);
    }

    public static function storeFolder(Workspace $workspace, string $folderId, string $folderName): void
    {
        $drive = self::dataFor($workspace);
        $drive['folder_id'] = $folderId;
        $drive['folder_name'] = $folderName;

        self::write($workspace, $drive);
    }

    public static function disconnect(Workspace $workspace): void
    {
        $settings = $workspace->settings ?? [];
        unset($settings['google_drive']);
        $workspace->settings = $settings;
        $workspace->save();
    }

    private function decrypt(string $key): ?string
    {
        $encrypted = $this->data[$key] ?? null;

        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private static function dataFor(Workspace $workspace): array
    {
        $settings = $workspace->settings ?? [];
        $drive = $settings['google_drive'] ?? [];

        return is_array($drive) ? $drive : [];
    }

    /** @param array<string, mixed> $drive */
    private static function write(Workspace $workspace, array $drive): void
    {
        $settings = $workspace->settings ?? [];
        $settings['google_drive'] = $drive;
        $workspace->settings = $settings;
        $workspace->save();
    }
}
