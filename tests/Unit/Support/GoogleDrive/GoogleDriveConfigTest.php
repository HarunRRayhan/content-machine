<?php

namespace Tests\Unit\Support\GoogleDrive;

use App\Models\Workspace;
use App\Support\GoogleDrive\GoogleDriveConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoogleDriveConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_tokens_and_folder_are_stored_for_a_workspace(): void
    {
        $workspace = Workspace::factory()->create();

        GoogleDriveConfig::storeTokens($workspace, [
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => 2_000_000_000,
            'email' => 'drive@example.com',
        ]);
        GoogleDriveConfig::storeFolder($workspace, 'folder-id', 'Content');

        $config = GoogleDriveConfig::fromWorkspace($workspace->fresh());

        $this->assertTrue($config->isConnected());
        $this->assertSame('access-token', $config->accessToken());
        $this->assertSame('refresh-token', $config->refreshToken());
        $this->assertSame(2_000_000_000, $config->accessTokenExpiresAt());
        $this->assertSame('drive@example.com', $config->connectedEmail());
        $this->assertSame('folder-id', $config->folderId());
        $this->assertSame('Content', $config->folderName());
        $this->assertNotSame(
            'refresh-token',
            $workspace->fresh()->settings['google_drive']['refresh_token'],
        );
    }

    public function test_disconnect_removes_the_drive_settings(): void
    {
        $workspace = Workspace::factory()->create();
        GoogleDriveConfig::storeTokens($workspace, [
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => 2_000_000_000,
        ]);

        GoogleDriveConfig::disconnect($workspace);

        $this->assertFalse(GoogleDriveConfig::fromWorkspace($workspace->fresh())->isConnected());
        $this->assertArrayNotHasKey('google_drive', $workspace->fresh()->settings);
    }
}
