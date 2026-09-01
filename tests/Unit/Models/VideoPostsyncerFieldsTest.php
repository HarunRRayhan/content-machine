<?php

namespace Tests\Unit\Models;

use App\Models\Video;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VideoPostsyncerFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_video_persists_postsyncer_publish_fields(): void
    {
        $workspace = Workspace::factory()->create();
        $video = Video::factory()->for($workspace)->create([
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'cover_drive_url' => 'https://drive.google.com/file/d/cover/view',
            'publish_state' => 'idle',
            'postsyncer' => ['groups' => []],
            'publish_progress' => [
                'version' => 1,
                'operation_id' => 'operation-1',
                'run_token' => 'run-1',
                'state' => 'failed',
                'completed_groups' => [],
            ],
        ]);

        $video->refresh();

        $this->assertSame(['groups' => []], $video->postsyncer);
        $this->assertSame('operation-1', $video->publish_progress['operation_id']);
        $this->assertSame('run-1', $video->publish_progress['run_token']);
        $this->assertSame('failed', $video->publish_progress['state']);
    }

    public function test_retryability_requires_a_deterministic_failed_checkpoint(): void
    {
        $video = Video::factory()->create([
            'publish_state' => 'failed',
            'publish_progress' => [
                'state' => 'failed',
                'current' => null,
            ],
        ]);

        $this->assertTrue($video->canRetryPublish());

        $video->forceFill([
            'publish_progress' => [
                'state' => 'uncertain',
                'current' => ['index' => 0],
            ],
        ])->save();

        $this->assertFalse($video->fresh()->canRetryPublish());
    }
}
