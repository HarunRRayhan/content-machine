<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class PublishMediaControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_url_streams_post_attachment_without_auth(): void
    {
        Storage::fake('scratchpad');

        $workspace = Workspace::factory()->create();
        $post = Post::factory()->for($workspace)->create();
        $media = MediaAsset::factory()->for($workspace)->create([
            'disk' => 'scratchpad',
            'path' => 'posts/test.png',
            'mime' => 'image/png',
            'original_filename' => 'test.png',
        ]);
        Storage::disk('scratchpad')->put('posts/test.png', 'png-bytes');

        Attachment::factory()->for($post, 'attachable')->for($media)->create();

        $url = URL::temporarySignedRoute(
            'publish-media.post',
            now()->addHour(),
            ['post' => $post->id, 'mediaAsset' => $media->id],
        );

        $this->get($url)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_signed_url_validates_https_scheme_from_reverse_proxy(): void
    {
        Storage::fake('scratchpad');

        $workspace = Workspace::factory()->create();
        $post = Post::factory()->for($workspace)->create();
        $media = MediaAsset::factory()->for($workspace)->create([
            'disk' => 'scratchpad',
            'path' => 'posts/proxied.png',
            'mime' => 'image/png',
            'original_filename' => 'proxied.png',
        ]);
        Storage::disk('scratchpad')->put('posts/proxied.png', 'png-bytes');

        Attachment::factory()->for($post, 'attachable')->for($media)->create();

        URL::forceRootUrl('https://cm.harun.dev');
        URL::forceScheme('https');
        $url = URL::temporarySignedRoute(
            'publish-media.post',
            now()->addHour(),
            ['post' => $post->id, 'mediaAsset' => $media->id],
        );

        $response = $this->withServerVariables([
            'HTTPS' => 'off',
            'SERVER_PORT' => 80,
        ])->withHeaders([
            'Host' => 'cm.harun.dev',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'cm.harun.dev',
        ])->get($url);

        $response->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_unsigned_url_is_rejected(): void
    {
        $workspace = Workspace::factory()->create();
        $post = Post::factory()->for($workspace)->create();
        $media = MediaAsset::factory()->for($workspace)->create();

        Attachment::factory()->for($post, 'attachable')->for($media)->create();

        $this->get(route('publish-media.post', ['post' => $post->id, 'mediaAsset' => $media->id]))
            ->assertForbidden();
    }
}
