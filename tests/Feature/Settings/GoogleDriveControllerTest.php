<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Models\Workspace;
use App\Support\GoogleDrive\GoogleDriveConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleDriveControllerTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Workspace} */
    private function actingAsWorkspaceOwner(): array
    {
        $workspace = Workspace::factory()->create();
        $team = $workspace->team;
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->members()->attach($user->id, ['role' => 'owner']);

        $this->actingAs($user);
        Config::set([
            'services.google_drive.client_id' => 'drive-client-id',
            'services.google_drive.client_secret' => 'drive-client-secret',
            'services.google_drive.redirect' => 'https://cm.example/settings/google-drive/callback',
        ]);

        return [$user, $workspace];
    }

    public function test_owner_can_start_oauth_with_state_and_pkce(): void
    {
        [, $workspace] = $this->actingAsWorkspaceOwner();

        $response = $this->get(route('settings.google-drive.connect'));
        $location = (string) $response->headers->get('Location');

        $response->assertRedirect();
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $location);
        $this->assertStringContainsString('client_id=drive-client-id', $location);
        $this->assertStringContainsString('code_challenge=', $location);
        $this->assertStringContainsString('scope=https%3A%2F%2Fwww.googleapis.com%2Fauth%2Fdrive', $location);
        $response->assertSessionHas('google_drive_oauth_workspace_id', $workspace->id);
    }

    public function test_oauth_callback_stores_encrypted_tokens(): void
    {
        [, $workspace] = $this->actingAsWorkspaceOwner();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
                'expires_in' => 3600,
            ]),
            'https://www.googleapis.com/drive/v3/about*' => Http::response([
                'user' => ['emailAddress' => 'drive@example.com'],
            ]),
        ]);

        $response = $this->withSession([
            'google_drive_oauth_state' => 'state-token',
            'google_drive_oauth_verifier' => 'verifier-token',
            'google_drive_oauth_workspace_id' => $workspace->id,
        ])->get(route('settings.google-drive.callback').'?state=state-token&code=auth-code');

        $response->assertRedirect(route('settings.google-drive.edit'));
        $config = GoogleDriveConfig::fromWorkspace($workspace->fresh());

        $this->assertTrue($config->isConnected());
        $this->assertSame('access-token', $config->accessToken());
        $this->assertSame('refresh-token', $config->refreshToken());
        $this->assertSame('drive@example.com', $config->connectedEmail());
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['code'] === 'auth-code'
            && $request['code_verifier'] === 'verifier-token');
    }

    public function test_connected_owner_can_list_files_in_the_default_folder(): void
    {
        [, $workspace] = $this->actingAsWorkspaceOwner();
        GoogleDriveConfig::storeTokens($workspace, [
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour()->timestamp,
        ]);
        GoogleDriveConfig::storeFolder($workspace, 'content-folder', 'Content');
        Http::fake([
            'https://www.googleapis.com/drive/v3/files/content-folder*' => Http::response([
                'id' => 'content-folder',
                'name' => 'Content',
                'mimeType' => 'application/vnd.google-apps.folder',
                'parents' => ['root'],
            ]),
            'https://www.googleapis.com/drive/v3/files?*' => Http::response([
                'files' => [[
                    'id' => 'video-id',
                    'name' => '062-final.mp4',
                    'mimeType' => 'video/mp4',
                    'size' => '1234',
                    'permissions' => [['type' => 'anyone', 'role' => 'reader']],
                ]],
            ]),
        ]);

        $this->get(route('settings.google-drive.files'))
            ->assertOk()
            ->assertJsonPath('current_folder.id', 'content-folder')
            ->assertJsonPath('current_folder.name', 'Content')
            ->assertJsonPath('files.0.id', 'video-id')
            ->assertJsonPath('files.0.is_public', true)
            ->assertJsonPath('files.0.share_url', 'https://drive.google.com/file/d/video-id/view?usp=sharing');
    }

    public function test_owner_can_make_a_private_file_public(): void
    {
        [, $workspace] = $this->actingAsWorkspaceOwner();
        GoogleDriveConfig::storeTokens($workspace, [
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour()->timestamp,
        ]);
        $metadataCalls = 0;
        Http::fake(function (ClientRequest $request) use (&$metadataCalls) {
            if (str_contains($request->url(), '/permissions')) {
                return Http::response(['id' => 'anyoneWithLink']);
            }

            $metadataCalls++;

            return Http::response([
                'id' => 'private-id',
                'name' => '063-final.mp4',
                'mimeType' => 'video/mp4',
                'permissions' => $metadataCalls === 1
                    ? []
                    : [['type' => 'anyone', 'role' => 'reader']],
            ]);
        });

        $response = $this->postJson(route('settings.google-drive.make-public'), ['file_id' => 'private-id']);

        $response
            ->assertOk()
            ->assertJsonPath('file.is_public', true);

        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/permissions')
            && $request['type'] === 'anyone'
            && $request['role'] === 'reader');
    }
}
