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
                'https://drive.google.com/file/d/abc/view',
                'https://drive.google.com/file/d/def/view',
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
        Storage::fake('local');

        $workspace = Workspace::factory()->create();
        $post = Post::factory()->for($workspace)->create([
            'image_drive_urls' => ['https://drive.google.com/file/d/ignored/view'],
        ]);

        $media = MediaAsset::factory()->for($workspace)->create([
            'disk' => 'local',
            'path' => 'posts/cover.jpg',
        ]);
        Storage::disk('local')->put('posts/cover.jpg', 'bytes');

        Attachment::factory()->for($post, 'attachable')->for($media)->create([
            'position' => 0,
        ]);

        $urls = $this->resolver->forPost($post->fresh());

        $this->assertCount(1, $urls);
        $this->assertSame(Storage::disk('local')->url('posts/cover.jpg'), $urls[0]);
    }

    public function test_for_post_returns_attachment_urls_in_position_order(): void
    {
        Storage::fake('local');

        $workspace = Workspace::factory()->create();
        $post = Post::factory()->for($workspace)->create();

        $first = MediaAsset::factory()->for($workspace)->create([
            'disk' => 'local',
            'path' => 'posts/first.jpg',
        ]);
        $second = MediaAsset::factory()->for($workspace)->create([
            'disk' => 'local',
            'path' => 'posts/second.jpg',
        ]);
        Storage::disk('local')->put('posts/first.jpg', 'one');
        Storage::disk('local')->put('posts/second.jpg', 'two');

        Attachment::factory()->for($post, 'attachable')->for($second)->create(['position' => 1]);
        Attachment::factory()->for($post, 'attachable')->for($first)->create(['position' => 0]);

        $urls = $this->resolver->forPost($post->fresh());

        $this->assertSame([
            Storage::disk('local')->url('posts/first.jpg'),
            Storage::disk('local')->url('posts/second.jpg'),
        ], $urls);
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

        $this->assertSame([], $this->resolver->forPost($post->fresh()));
    }

    public function test_for_video_returns_drive_urls(): void
    {
        $video = Video::factory()->create([
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'cover_drive_url' => 'https://drive.google.com/file/d/cover/view',
        ]);

        $this->assertSame([
            'video' => 'https://drive.google.com/file/d/video/view',
            'cover' => 'https://drive.google.com/file/d/cover/view',
        ], $this->resolver->forVideo($video));
    }

    public function test_for_video_allows_missing_cover(): void
    {
        $video = Video::factory()->create([
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'cover_drive_url' => null,
        ]);

        $this->assertSame([
            'video' => 'https://drive.google.com/file/d/video/view',
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
