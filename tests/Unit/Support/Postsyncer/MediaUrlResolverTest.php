<?php

namespace Tests\Unit\Support\Postsyncer;

use App\Models\Attachment;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Postsyncer\MediaUrlResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class MediaUrlResolverTest extends TestCase
{
    use RefreshDatabase;

    private MediaUrlResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new MediaUrlResolver;
    }

    public function test_for_post_returns_image_drive_urls_when_no_attachments(): void
    {
        $post = Post::factory()->create([
            'image_drive_urls' => [
                'https://drive.google.com/file/d/abc/view',
                'https://drive.google.com/file/d/def/view',
            ],
        ]);

        $this->assertSame(
            [
                'https://drive.usercontent.google.com/download?id=abc&export=download&confirm=t',
                'https://drive.usercontent.google.com/download?id=def&export=download&confirm=t',
            ],
            $this->resolver->forPost($post),
        );
    }

    public function test_for_post_returns_empty_array_when_no_media_sources(): void
    {
        $post = Post::factory()->create([
            'image_drive_urls' => null,
        ]);

        $this->assertSame([], $this->resolver->forPost($post));
    }

    public function test_for_post_prefers_attachment_storage_urls_over_drive_urls(): void
    {
        Storage::fake('scratchpad');

        $workspace = Workspace::factory()->create();
        $post = Post::factory()->for($workspace)->create([
            'image_drive_urls' => ['https://drive.google.com/file/d/ignored/view'],
        ]);

        $media = MediaAsset::factory()->for($workspace)->create([
            'disk' => 'scratchpad',
            'path' => 'posts/cover.jpg',
            'original_filename' => 'cover.jpg',
        ]);
        Storage::disk('scratchpad')->put('posts/cover.jpg', 'bytes');

        Attachment::factory()->for($post, 'attachable')->for($media)->create([
            'position' => 0,
        ]);

        $urls = $this->resolver->forPost($post->fresh());

        $this->assertCount(1, $urls);
        $this->assertStringContainsString('publish-media/posts/', $urls[0]);
        $this->assertStringContainsString('signature=', $urls[0]);
    }

    public function test_for_post_returns_attachment_urls_in_position_order(): void
    {
        Storage::fake('scratchpad');

        $workspace = Workspace::factory()->create();
        $post = Post::factory()->for($workspace)->create();

        $first = MediaAsset::factory()->for($workspace)->create([
            'disk' => 'scratchpad',
            'path' => 'posts/first.jpg',
            'original_filename' => 'first.jpg',
        ]);
        $second = MediaAsset::factory()->for($workspace)->create([
            'disk' => 'scratchpad',
            'path' => 'posts/second.jpg',
            'original_filename' => 'second.jpg',
        ]);
        Storage::disk('scratchpad')->put('posts/first.jpg', 'one');
        Storage::disk('scratchpad')->put('posts/second.jpg', 'two');

        Attachment::factory()->for($post, 'attachable')->for($second)->create(['position' => 1]);
        Attachment::factory()->for($post, 'attachable')->for($first)->create(['position' => 0]);

        $urls = $this->resolver->forPost($post->fresh());

        $this->assertCount(2, $urls);
        $this->assertStringContainsString('/publish-media/posts/', $urls[0]);
        $this->assertStringContainsString('/publish-media/posts/', $urls[1]);
    }

    public function test_resolve_named_images_maps_attachment_filenames(): void
    {
        Storage::fake('scratchpad');

        $workspace = Workspace::factory()->create();
        $post = Post::factory()->for($workspace)->create();

        $media = MediaAsset::factory()->for($workspace)->create([
            'disk' => 'scratchpad',
            'path' => 'posts/cover.png',
            'original_filename' => 'P49-cover.png',
        ]);
        Storage::disk('scratchpad')->put('posts/cover.png', 'bytes');

        Attachment::factory()->for($post, 'attachable')->for($media)->create(['position' => 0]);

        $urls = $this->resolver->resolveNamedImages($post->fresh(), ['P49-cover.png']);

        $this->assertCount(1, $urls);
        $this->assertStringContainsString('publish-media/posts/', $urls[0]);
    }

    public function test_for_post_skips_attachments_without_resolvable_urls(): void
    {
        $workspace = Workspace::factory()->create();
        $post = Post::factory()->for($workspace)->create([
            'image_drive_urls' => ['https://drive.google.com/file/d/fallback/view'],
        ]);

        Attachment::factory()->for($post, 'attachable')->create([
            'media_asset_id' => MediaAsset::factory()->for($workspace)->create([
                'disk' => 'missing-disk',
                'path' => 'posts/missing.jpg',
            ])->id,
            'position' => 0,
        ]);

        $this->assertSame(
            ['https://drive.usercontent.google.com/download?id=fallback&export=download&confirm=t'],
            $this->resolver->forPost($post->fresh()),
        );
    }

    public function test_for_video_returns_drive_urls(): void
    {
        $video = Video::factory()->create([
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'cover_drive_url' => 'https://drive.google.com/file/d/cover/view',
        ]);

        $this->assertSame([
            'video' => 'https://drive.usercontent.google.com/download?id=video&export=download&confirm=t',
            'cover' => 'https://drive.usercontent.google.com/download?id=cover&export=download&confirm=t',
        ], $this->resolver->forVideo($video));
    }

    public function test_for_video_allows_missing_cover(): void
    {
        $video = Video::factory()->create([
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'cover_drive_url' => null,
        ]);

        $this->assertSame([
            'video' => 'https://drive.usercontent.google.com/download?id=video&export=download&confirm=t',
            'cover' => null,
        ], $this->resolver->forVideo($video));
    }

    public function test_for_video_requires_video_drive_url(): void
    {
        $video = Video::factory()->create([
            'video_drive_url' => null,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('video_drive_url');

        $this->resolver->forVideo($video);
    }
}
