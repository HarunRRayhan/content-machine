<?php

namespace Tests\Feature\Api;

use App\Actions\ApiTokens\CreateWorkspaceApiTokenAction;
use App\Data\ApiTokens\CreateWorkspaceApiTokenData;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Pexels\PexelsConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PexelsApiTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p1sAAAAASUVORK5CYII=';

    private function token(Workspace $workspace, array $abilities = ['media:read', 'media:write']): string
    {
        return (new CreateWorkspaceApiTokenAction)->handle(
            $workspace,
            User::factory()->create(),
            new CreateWorkspaceApiTokenData('pexels test', $abilities),
        )['plaintext'];
    }

    /** @return array<string, mixed> */
    private function photo(int $id = 123): array
    {
        return [
            'id' => $id,
            'photographer' => 'A Photographer',
            'photographer_url' => 'https://www.pexels.com/@photographer/',
            'url' => 'https://www.pexels.com/photo/test-photo-'.$id.'/',
            'src' => [
                'medium' => 'https://images.pexels.com/photos/'.$id.'/medium.jpg',
                'large' => 'https://images.pexels.com/photos/'.$id.'/large.jpg',
                'large2x' => 'https://images.pexels.com/photos/'.$id.'/large2x.jpg',
                'original' => 'https://images.pexels.com/photos/'.$id.'/original.png',
            ],
        ];
    }

    private function configure(Workspace $workspace): void
    {
        PexelsConfig::write($workspace, 'workspace-pexels-secret');
    }

    public function test_search_uses_workspace_key_and_returns_attribution_and_ordered_results(): void
    {
        $workspace = Workspace::factory()->create();
        $this->configure($workspace);
        $token = $this->token($workspace, ['media:read']);
        Http::fake(['https://api.pexels.com/v1/search*' => Http::response([
            'photos' => [$this->photo(123), $this->photo(456)],
        ])]);

        $this->withToken($token)->getJson('/api/v1/pexels/search?query=street&orientation=portrait&per_page=6')
            ->assertOk()
            ->assertJsonPath('data.0.id', 123)
            ->assertJsonPath('data.1.id', 456)
            ->assertJsonPath('data.0.photographer', 'A Photographer')
            ->assertJsonPath('data.0.photographer_url', 'https://www.pexels.com/@photographer/')
            ->assertJsonPath('data.0.pexels_url', 'https://www.pexels.com/photo/test-photo-123/');

        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://api.pexels.com/v1/search?query=street&orientation=portrait&per_page=6'
            && $request->hasHeader('Authorization', 'workspace-pexels-secret'));
    }

    public function test_import_fetches_photo_by_id_stores_asset_and_keeps_key_off_cdn_download(): void
    {
        Storage::fake('scratchpad');
        $workspace = Workspace::factory()->create();
        $this->configure($workspace);
        $token = $this->token($workspace, ['media:write']);
        Http::fake([
            'https://api.pexels.com/v1/photos/123' => Http::response($this->photo()),
            'https://images.pexels.com/*' => Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'image/png']),
        ]);

        $response = $this->withToken($token)->postJson('/api/v1/pexels/import', ['id' => 123]);

        $response->assertCreated()
            ->assertJsonPath('data.id', 123)
            ->assertJsonPath('data.photographer', 'A Photographer')
            ->assertJsonPath('data.asset.kind', 'image')
            ->assertJsonPath('data.asset.preview_url', route('api.v1.media.file', ['mediaAsset' => $response->json('data.asset.public_id')]));
        $asset = MediaAsset::query()->sole();
        $this->assertSame($workspace->id, $asset->workspace_id);
        $this->assertSame('pexels', $asset->meta['source']);
        $this->assertSame(123, $asset->meta['pexels']['id']);
        $this->assertSame('A Photographer', $asset->meta['pexels']['photographer']);
        Storage::disk('scratchpad')->assertExists($asset->path);
        Http::assertSent(fn (ClientRequest $request): bool => str_starts_with($request->url(), 'https://api.pexels.com/v1/photos/123')
            && $request->hasHeader('Authorization', 'workspace-pexels-secret'));
        Http::assertSent(fn (ClientRequest $request): bool => str_starts_with($request->url(), 'https://images.pexels.com/')
            && ! $request->hasHeader('Authorization'));
    }

    public function test_api_uses_token_workspace_and_existing_media_abilities(): void
    {
        $workspace = Workspace::factory()->create();
        $this->configure($workspace);
        $readOnlyToken = $this->token($workspace, ['media:read']);
        $writeOnlyToken = $this->token($workspace, ['media:write']);
        Http::fake(['https://api.pexels.com/v1/search*' => Http::response(['photos' => []])]);

        $this->withToken($writeOnlyToken)->getJson('/api/v1/pexels/search?query=tree')->assertForbidden();
        $this->withToken($readOnlyToken)->postJson('/api/v1/pexels/import', ['id' => 123])->assertForbidden();
    }

    public function test_missing_credentials_and_provider_failures_are_sanitized(): void
    {
        $workspace = Workspace::factory()->create();
        $token = $this->token($workspace);
        Http::fake();
        $this->withToken($token)->getJson('/api/v1/pexels/search?query=tree')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Pexels is not configured for this workspace.');
        Http::assertNothingSent();

        $this->configure($workspace);
        Http::fake(['https://api.pexels.com/v1/search*' => Http::response(['error' => 'unauthorized'], 401)]);
        $this->withToken($token)->getJson('/api/v1/pexels/search?query=tree')
            ->assertStatus(502)
            ->assertJsonPath('message', 'Pexels search failed. Check the workspace API key and try again.');
    }

    public function test_search_connection_failure_returns_a_sanitized_provider_error(): void
    {
        $workspace = Workspace::factory()->create();
        $this->configure($workspace);
        $token = $this->token($workspace, ['media:read']);
        Http::fake(['https://api.pexels.com/v1/search*' => Http::failedConnection('private connection details')]);

        $this->withToken($token)->getJson('/api/v1/pexels/search?query=tree')
            ->assertStatus(502)
            ->assertExactJson(['message' => 'Pexels search failed. Check the workspace API key and try again.']);
    }

    public function test_import_photo_lookup_connection_failure_returns_a_sanitized_provider_error(): void
    {
        $workspace = Workspace::factory()->create();
        $this->configure($workspace);
        $token = $this->token($workspace, ['media:write']);
        Http::fake(['https://api.pexels.com/v1/photos/123' => Http::failedConnection('private connection details')]);

        $this->withToken($token)->postJson('/api/v1/pexels/import', ['id' => 123])
            ->assertStatus(502)
            ->assertExactJson(['message' => 'Pexels photo import failed. Check the photo ID and try again.']);
        $this->assertDatabaseCount('media_assets', 0);
        Http::assertSentCount(1);
    }

    public function test_import_rejects_provider_download_urls_outside_pexels_image_host(): void
    {
        Storage::fake('scratchpad');
        $workspace = Workspace::factory()->create();
        $this->configure($workspace);
        $token = $this->token($workspace, ['media:write']);
        $photo = $this->photo();
        $photo['src']['original'] = 'https://attacker.example/image.png';
        Http::fake(['https://api.pexels.com/v1/photos/123' => Http::response($photo)]);

        $this->withToken($token)->postJson('/api/v1/pexels/import', ['id' => 123])
            ->assertStatus(502)
            ->assertJsonPath('message', 'Pexels photo import failed. Check the photo ID and try again.');
        $this->assertDatabaseCount('media_assets', 0);
        Http::assertSentCount(1);
    }

    public function test_import_rejects_nonstandard_image_source_ports_without_downloading(): void
    {
        Storage::fake('scratchpad');
        $workspace = Workspace::factory()->create();
        $this->configure($workspace);
        $token = $this->token($workspace, ['media:write']);
        $photo = $this->photo();
        $photo['src']['original'] = 'https://images.pexels.com:8443/photos/123/original.png';
        Http::fake(['https://api.pexels.com/v1/photos/123' => Http::response($photo)]);

        $this->withToken($token)->postJson('/api/v1/pexels/import', ['id' => 123])
            ->assertStatus(502)
            ->assertJsonPath('message', 'Pexels photo import failed. Check the photo ID and try again.');
        $this->assertDatabaseCount('media_assets', 0);
        Http::assertSentCount(1);
    }

    public function test_import_rejects_image_source_userinfo_without_downloading(): void
    {
        Storage::fake('scratchpad');
        $workspace = Workspace::factory()->create();
        $this->configure($workspace);
        $token = $this->token($workspace, ['media:write']);
        $photo = $this->photo();
        $photo['src']['original'] = 'https://user:password@images.pexels.com/photos/123/original.png';
        Http::fake(['https://api.pexels.com/v1/photos/123' => Http::response($photo)]);

        $this->withToken($token)->postJson('/api/v1/pexels/import', ['id' => 123])
            ->assertStatus(502)
            ->assertJsonPath('message', 'Pexels photo import failed. Check the photo ID and try again.');
        $this->assertDatabaseCount('media_assets', 0);
        Http::assertSentCount(1);
    }

    public function test_import_rejects_non_image_or_oversized_downloads(): void
    {
        Storage::fake('scratchpad');
        $workspace = Workspace::factory()->create();
        $this->configure($workspace);
        $token = $this->token($workspace, ['media:write']);
        Http::fake([
            'https://api.pexels.com/v1/photos/123' => Http::response($this->photo()),
            'https://images.pexels.com/*' => Http::response('not an image', 200, ['Content-Type' => 'image/png']),
        ]);

        $this->withToken($token)->postJson('/api/v1/pexels/import', ['id' => 123])->assertStatus(502);
        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_import_rejects_declared_download_size_above_limit(): void
    {
        Storage::fake('scratchpad');
        $workspace = Workspace::factory()->create();
        $this->configure($workspace);
        $token = $this->token($workspace, ['media:write']);
        Http::fake([
            'https://api.pexels.com/v1/photos/123' => Http::response($this->photo()),
            'https://images.pexels.com/*' => Http::response('image data', 200, [
                'Content-Type' => 'image/png',
                'Content-Length' => (string) (20 * 1024 * 1024 + 1),
            ]),
        ]);

        $this->withToken($token)->postJson('/api/v1/pexels/import', ['id' => 123])->assertStatus(502);
        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_each_token_uses_only_its_workspace_credential(): void
    {
        $first = Workspace::factory()->create();
        $second = Workspace::factory()->create();
        PexelsConfig::write($first, 'first-workspace-key');
        PexelsConfig::write($second, 'second-workspace-key');
        $token = $this->token($first, ['media:read']);
        Http::fake(['https://api.pexels.com/v1/search*' => Http::response([
            'photos' => [$this->photo()],
        ])]);

        $this->withToken($token)->getJson('/api/v1/pexels/search?query=tree')->assertOk();
        Http::assertSent(fn (ClientRequest $request): bool => $request->hasHeader('Authorization', 'first-workspace-key')
            && ! $request->hasHeader('Authorization', 'second-workspace-key'));
    }
}
