<?php

namespace Tests\Feature\Api;

use App\Actions\ApiTokens\CreateWorkspaceApiTokenAction;
use App\Data\ApiTokens\CreateWorkspaceApiTokenData;
use App\Models\User;
use App\Models\Workspace;
use App\Support\GoogleDrive\GoogleDriveConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleDriveApiTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<int, string> $abilities */
    private function mintToken(Workspace $workspace, array $abilities): string
    {
        return (new CreateWorkspaceApiTokenAction)->handle(
            $workspace,
            User::factory()->create(),
            new CreateWorkspaceApiTokenData('drive test client', $abilities),
        )['plaintext'];
    }

    private function connectDrive(Workspace $workspace): void
    {
        Config::set([
            'services.google_drive.client_id' => 'drive-client-id',
            'services.google_drive.client_secret' => 'drive-client-secret',
        ]);

        GoogleDriveConfig::storeTokens($workspace, [
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour()->timestamp,
        ]);
    }

    public function test_drive_file_listing_requires_the_read_ability(): void
    {
        $workspace = Workspace::factory()->create();
        $token = $this->mintToken($workspace, ['videos:read']);

        $this->withToken($token)
            ->getJson('/api/v1/google-drive/files')
            ->assertForbidden();
    }

    public function test_drive_file_listing_uses_the_workspace_connection(): void
    {
        $workspace = Workspace::factory()->create();
        $token = $this->mintToken($workspace, ['drive:read']);
        $this->connectDrive($workspace);

        Http::fake([
            'https://www.googleapis.com/drive/v3/files?*' => Http::response([
                'files' => [[
                    'id' => 'video-id',
                    'name' => '062-final.mp4',
                    'mimeType' => 'video/mp4',
                    'permissions' => [['type' => 'anyone', 'role' => 'reader']],
                ]],
            ]),
        ]);

        $this->withToken($token)
            ->getJson('/api/v1/google-drive/files?q=062')
            ->assertOk()
            ->assertJsonPath('files.0.id', 'video-id')
            ->assertJsonPath('files.0.is_public', true)
            ->assertJsonPath('files.0.share_url', 'https://drive.google.com/file/d/video-id/view?usp=sharing');
    }

    public function test_making_a_file_public_requires_the_write_ability(): void
    {
        $workspace = Workspace::factory()->create();
        $token = $this->mintToken($workspace, ['drive:read']);

        $this->withToken($token)
            ->postJson('/api/v1/google-drive/files/file-id/make-public')
            ->assertForbidden();
    }

    public function test_making_a_file_public_returns_the_updated_file(): void
    {
        $workspace = Workspace::factory()->create();
        $token = $this->mintToken($workspace, ['drive:write']);
        $this->connectDrive($workspace);
        $metadataCalls = 0;

        Http::fake(function (ClientRequest $request) use (&$metadataCalls) {
            if (str_contains($request->url(), '/permissions')) {
                return Http::response(['id' => 'anyoneWithLink']);
            }

            $metadataCalls++;

            return Http::response([
                'id' => 'file-id',
                'name' => '062-final.mp4',
                'mimeType' => 'video/mp4',
                'permissions' => $metadataCalls === 1
                    ? []
                    : [['type' => 'anyone', 'role' => 'reader']],
            ]);
        });

        $this->withToken($token)
            ->postJson('/api/v1/google-drive/files/file-id/make-public')
            ->assertOk()
            ->assertJsonPath('file.id', 'file-id')
            ->assertJsonPath('file.is_public', true)
            ->assertJsonPath('file.share_url', 'https://drive.google.com/file/d/file-id/view?usp=sharing');

        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/permissions')
            && $request['type'] === 'anyone'
            && $request['role'] === 'reader');
    }
}
