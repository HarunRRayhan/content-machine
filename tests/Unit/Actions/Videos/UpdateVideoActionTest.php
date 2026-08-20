<?php

namespace Tests\Unit\Actions\Videos;

use App\Actions\Videos\UpdateVideoAction;
use App\Data\Videos\UpdateVideoData;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateVideoActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_the_editable_fields()
    {
        $video = Video::factory()->create([
            'title' => 'Old title',
            'body' => 'Old body.',
        ]);

        $updated = (new UpdateVideoAction)->handle($video, new UpdateVideoData(
            title: 'New title',
            body: 'New body.',
        ));

        $this->assertSame('New title', $updated->title);
        $this->assertSame('New body.', $updated->body);

        $this->assertDatabaseHas('videos', [
            'id' => $video->id,
            'title' => 'New title',
            'body' => 'New body.',
        ]);
    }

    public function test_it_does_not_touch_number_human_id_status_or_idea_id()
    {
        $video = Video::factory()->create([
            'number' => 4,
            'human_id' => 'V-4',
            'status' => 'draft',
            'idea_id' => null,
        ]);

        (new UpdateVideoAction)->handle($video, new UpdateVideoData(title: 'Renamed', body: null));

        $video->refresh();

        $this->assertSame(4, $video->number);
        $this->assertSame('V-4', $video->human_id);
        $this->assertSame('draft', $video->status);
        $this->assertNull($video->idea_id);
    }
}
