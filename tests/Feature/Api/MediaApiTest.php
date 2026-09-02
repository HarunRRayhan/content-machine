<?php

namespace Tests\Feature\Api;

use App\Actions\ApiTokens\CreateWorkspaceApiTokenAction;
use App\Data\ApiTokens\CreateWorkspaceApiTokenData;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_preview_url_works_for_a_token_client(): void
    {
        Storage::fake('scratchpad');
        $workspace = Workspace::factory()->create();
        $token = (new CreateWorkspaceApiTokenAction)->handle(
            $workspace,
            User::factory()->create(),
            new CreateWorkspaceApiTokenData('media client', ['media:read']),
        )['plaintext'];
        $asset = MediaAsset::factory()->for($workspace)->create([
            'disk' => 'scratchpad',
            'path' => 'media/preview.png',
            'mime' => 'image/png',
        ]);
        Storage::disk('scratchpad')->put($asset->path, 'png-bytes');

        $response = $this->withToken($token)->getJson('/api/v1/media');

        $response->assertOk()
            ->assertJsonPath('data.0.preview_url', route('api.v1.media.file', $asset->public_id));

        $this->withToken($token)
            ->get('/api/v1/media/'.$asset->public_id.'/file')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_a_token_cannot_stream_media_from_another_workspace(): void
    {
        Storage::fake('scratchpad');
        $workspace = Workspace::factory()->create();
        $token = (new CreateWorkspaceApiTokenAction)->handle(
            $workspace,
            User::factory()->create(),
            new CreateWorkspaceApiTokenData('media client', ['media:read']),
        )['plaintext'];
        $asset = MediaAsset::factory()->create([
            'disk' => 'scratchpad',
            'path' => 'media/secret.png',
            'mime' => 'image/png',
        ]);
        Storage::disk('scratchpad')->put($asset->path, 'secret');

        $this->withToken($token)
            ->get('/api/v1/media/'.$asset->public_id.'/file')
            ->assertNotFound();
    }
}
