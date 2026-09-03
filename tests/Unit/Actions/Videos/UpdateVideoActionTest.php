<?php

namespace Tests\Unit\Actions\Videos;

use App\Actions\Videos\UpdateVideoAction;
use App\Data\Videos\UpdateVideoData;
use App\Http\Requests\Videos\UpdateVideoRequest;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
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

    public function test_it_rejects_edits_while_a_postsyncer_publish_is_queued_or_running(): void
    {
        $video = Video::factory()->create([
            'title' => 'Original title',
            'publish_state' => 'running',
        ]);

        $this->expectException(ValidationException::class);

        (new UpdateVideoAction)->handle($video, new UpdateVideoData(
            title: 'Changed title',
            body: 'Changed body.',
        ));
    }

    public function test_it_rejects_edits_when_the_postsyncer_outcome_is_uncertain(): void
    {
        $video = Video::factory()->create([
            'title' => 'Original title',
            'publish_state' => 'failed',
            'publish_progress' => [
                'state' => 'uncertain',
                'current' => ['index' => 0],
            ],
        ]);

        $this->expectException(ValidationException::class);

        (new UpdateVideoAction)->handle($video, new UpdateVideoData(
            title: 'Changed title',
            body: 'Changed body.',
        ));
    }

    public function test_title_and_status_patch_keeps_existing_drive_urls(): void
    {
        $video = Video::factory()->create([
            'title' => 'Old title',
            'status' => 'pending',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'cover_drive_url' => 'https://drive.google.com/file/d/cover/view',
        ]);

        $request = UpdateVideoRequest::create('/videos/1', 'PATCH', [
            'title' => 'Old title',
            'status' => 'ready',
        ]);

        $updated = (new UpdateVideoAction)->handle($video, UpdateVideoData::fromRequest($request));

        $this->assertSame('ready', $updated->status);
        $this->assertSame('https://drive.google.com/file/d/video/view', $updated->video_drive_url);
        $this->assertSame('https://drive.google.com/file/d/cover/view', $updated->cover_drive_url);
    }

    public function test_empty_drive_url_fields_clear_existing_values(): void
    {
        $video = Video::factory()->create([
            'title' => 'Old title',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'cover_drive_url' => 'https://drive.google.com/file/d/cover/view',
        ]);

        $request = UpdateVideoRequest::create('/videos/1', 'PATCH', [
            'title' => 'Old title',
            'video_drive_url' => '',
            'cover_drive_url' => '',
        ]);

        $updated = (new UpdateVideoAction)->handle($video, UpdateVideoData::fromRequest($request));

        $this->assertNull($updated->video_drive_url);
        $this->assertNull($updated->cover_drive_url);
    }

    public function test_sending_one_drive_url_does_not_clear_the_other(): void
    {
        $video = Video::factory()->create([
            'title' => 'Old title',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'cover_drive_url' => 'https://drive.google.com/file/d/cover/view',
        ]);

        $request = UpdateVideoRequest::create('/videos/1', 'PATCH', [
            'title' => 'Old title',
            'video_drive_url' => 'https://drive.google.com/file/d/new-video/view',
        ]);

        $updated = (new UpdateVideoAction)->handle($video, UpdateVideoData::fromRequest($request));

        $this->assertSame('https://drive.google.com/file/d/new-video/view', $updated->video_drive_url);
        $this->assertSame('https://drive.google.com/file/d/cover/view', $updated->cover_drive_url);
    }

    public function test_api_payload_updates_script_without_wiping_drive_urls(): void
    {
        $video = Video::factory()->create([
            'title' => 'Old title',
            'body' => 'Old body.',
            'language' => 'bn',
            'slug' => 'old-slug',
            'script_markdown' => 'old script',
            'captions' => ['facebook' => 'old caption'],
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'cover_drive_url' => 'https://drive.google.com/file/d/cover/view',
        ]);

        $updated = (new UpdateVideoAction)->handle($video, UpdateVideoData::fromApiPayload([
            'title' => 'New title',
            'script_markdown' => 'spoken lines',
            'captions' => ['facebook' => 'new caption'],
        ], $video));

        $this->assertSame('New title', $updated->title);
        $this->assertSame('spoken lines', $updated->script_markdown);
        $this->assertSame(['facebook' => 'new caption'], $updated->captions);
        $this->assertSame('bn', $updated->language);
        $this->assertSame('old-slug', $updated->slug);
        $this->assertSame('https://drive.google.com/file/d/video/view', $updated->video_drive_url);
        $this->assertSame('https://drive.google.com/file/d/cover/view', $updated->cover_drive_url);
    }

    public function test_api_payload_writes_drive_urls_when_keys_are_present(): void
    {
        $video = Video::factory()->create([
            'title' => 'Old title',
            'video_drive_url' => null,
            'cover_drive_url' => null,
        ]);

        $updated = (new UpdateVideoAction)->handle($video, UpdateVideoData::fromApiPayload([
            'video_drive_url' => 'https://drive.google.com/file/d/new-video/view',
            'cover_drive_url' => 'https://drive.google.com/file/d/new-cover/view',
        ], $video));

        $this->assertSame('https://drive.google.com/file/d/new-video/view', $updated->video_drive_url);
        $this->assertSame('https://drive.google.com/file/d/new-cover/view', $updated->cover_drive_url);
    }

    public function test_partial_api_payload_preserves_extended_fields_from_a_newer_row(): void
    {
        $video = Video::factory()->create([
            'script_markdown' => 'old script',
            'deck_manifest' => ['js' => 'old deck'],
        ]);
        $data = UpdateVideoData::fromApiPayload([
            'deck_manifest' => ['js' => 'new deck'],
        ], $video);

        Video::query()->whereKey($video->id)->update([
            'script_markdown' => 'newer script',
        ]);

        (new UpdateVideoAction)->handle($video, $data);

        $video->refresh();
        $this->assertSame('newer script', $video->script_markdown);
        $this->assertSame(['js' => 'new deck'], $video->deck_manifest);
    }
}
