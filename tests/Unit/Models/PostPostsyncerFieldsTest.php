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
            'publish_progress' => [
                'version' => 1,
                'operation_id' => 'operation-1',
                'state' => 'failed',
                'completed_groups' => [],
            ],
        ]);

        $post->refresh();

        $this->assertSame(['https://drive.google.com/file/d/abc/view'], $post->image_drive_urls);
        $this->assertSame('idle', $post->publish_state);
        $this->assertSame(['groups' => []], $post->postsyncer);
        $this->assertSame('operation-1', $post->publish_progress['operation_id']);
        $this->assertSame('failed', $post->publish_progress['state']);
    }

    public function test_retryability_requires_a_deterministic_failed_checkpoint(): void
    {
        $post = Post::factory()->create([
            'publish_state' => 'failed',
            'publish_progress' => [
                'state' => 'failed',
                'current' => null,
            ],
        ]);

        $this->assertTrue($post->canRetryPublish());

        $post->forceFill([
            'publish_progress' => [
                'state' => 'uncertain',
                'current' => ['index' => 0],
            ],
        ])->save();

        $this->assertFalse($post->fresh()->canRetryPublish());
    }
}
