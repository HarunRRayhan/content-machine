<?php

namespace Tests\Unit\Actions\Pexels;

use App\Actions\Pexels\ImportPexelsPhotoAction;
use App\Contracts\PexelsProvider;
use App\Data\Pexels\ImportPexelsPhotoData;
use App\Data\Pexels\PexelsPhotoData;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ImportPexelsPhotoActionTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p1sAAAAASUVORK5CYII=';

    public function test_it_creates_a_workspace_asset_with_pexels_attribution(): void
    {
        Storage::fake('scratchpad');
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();
        $photo = new PexelsPhotoData(
            id: 123,
            photographer: 'A Photographer',
            photographerUrl: 'https://www.pexels.com/@photographer/',
            pexelsUrl: 'https://www.pexels.com/photo/123/',
            previewUrl: 'https://images.pexels.com/photos/123/medium.jpg',
            downloadUrl: 'https://images.pexels.com/photos/123/original.png',
        );
        $provider = Mockery::mock(PexelsProvider::class);
        $provider->shouldReceive('photo')->once()->with(123)->andReturn($photo);
        $provider->shouldReceive('download')->once()->with($photo)->andReturn([
            'bytes' => base64_decode(self::PNG),
            'mime' => 'image/png',
        ]);

        $asset = (new ImportPexelsPhotoAction)->handle(
            $workspace,
            $user,
            new ImportPexelsPhotoData(123),
            $provider,
        );

        $this->assertInstanceOf(MediaAsset::class, $asset);
        $this->assertSame($workspace->id, $asset->workspace_id);
        $this->assertSame($user->id, $asset->uploaded_by_user_id);
        $this->assertSame('image/png', $asset->mime);
        $this->assertSame('pexels', $asset->meta['source']);
        $this->assertSame(123, $asset->meta['pexels']['id']);
        $this->assertSame('A Photographer', $asset->meta['pexels']['photographer']);
        Storage::disk('scratchpad')->assertExists($asset->path);
    }
}
