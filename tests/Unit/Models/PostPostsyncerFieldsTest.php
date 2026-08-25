<?php

namespace Tests\Unit\Models;

use App\Models\Post;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostPostsyncerFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_persists_postsyncer_publish_fields(): void
    {
        $workspace = Workspace::factory()->create();
        $post = Post::factory()->for($workspace)->create([
            'image_drive_urls' => ['https://drive.google.com/file/d/abc/view'],
            'publish_state' => 'idle',
            'postsyncer' => ['groups' => []],
        ]);

        $post->refresh();

        $this->assertSame(['https://drive.google.com/file/d/abc/view'], $post->image_drive_urls);
        $this->assertSame('idle', $post->publish_state);
        $this->assertSame(['groups' => []], $post->postsyncer);
    }
}
