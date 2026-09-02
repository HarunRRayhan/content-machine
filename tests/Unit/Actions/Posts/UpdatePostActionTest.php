<?php

namespace Tests\Unit\Actions\Posts;

use App\Actions\Posts\UpdatePostAction;
use App\Data\Posts\UpdatePostData;
use App\Http\Requests\Posts\UpdatePostRequest;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UpdatePostActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_the_editable_fields()
    {
        $post = Post::factory()->create([
            'title' => 'Old title',
            'body' => 'Old body.',
        ]);

        $updated = (new UpdatePostAction)->handle($post, new UpdatePostData(
            title: 'New title',
            body: 'New body.',
        ));

        $this->assertSame('New title', $updated->title);
        $this->assertSame('New body.', $updated->body);

        $this->assertDatabaseHas('posts', [
            'id' => $post->id,
            'title' => 'New title',
            'body' => 'New body.',
        ]);
    }

    public function test_it_does_not_touch_number_human_id_status_or_idea_id()
    {
        $post = Post::factory()->create([
            'number' => 4,
            'human_id' => 'P-4',
            'status' => 'draft',
            'idea_id' => null,
        ]);

        (new UpdatePostAction)->handle($post, new UpdatePostData(title: 'Renamed', body: null));

        $post->refresh();

        $this->assertSame(4, $post->number);
        $this->assertSame('P-4', $post->human_id);
        $this->assertSame('draft', $post->status);
        $this->assertNull($post->idea_id);
    }

    public function test_it_rejects_edits_while_a_postsyncer_publish_is_in_progress(): void
    {
        $post = Post::factory()->create([
            'title' => 'Original title',
            'publish_state' => 'running',
        ]);

        $this->expectException(ValidationException::class);

        (new UpdatePostAction)->handle($post, new UpdatePostData(
            title: 'Changed title',
            body: 'Changed body.',
        ));
    }

    public function test_it_rejects_edits_when_the_postsyncer_outcome_is_uncertain(): void
    {
        $post = Post::factory()->create([
            'title' => 'Original title',
            'publish_state' => 'failed',
            'publish_progress' => [
                'state' => 'uncertain',
                'current' => ['index' => 0],
            ],
        ]);

        $this->expectException(ValidationException::class);

        (new UpdatePostAction)->handle($post, new UpdatePostData(
            title: 'Changed title',
            body: 'Changed body.',
        ));
    }

    public function test_title_and_status_patch_keeps_existing_image_drive_urls(): void
    {
        $post = Post::factory()->create([
            'title' => 'Old title',
            'status' => 'draft',
            'image_drive_urls' => ['https://drive.google.com/file/d/photo/view'],
        ]);

        $request = UpdatePostRequest::create('/posts/1', 'PATCH', [
            'title' => 'Old title',
            'status' => 'ready',
        ]);

        $updated = (new UpdatePostAction)->handle($post, UpdatePostData::fromRequest($request));

        $this->assertSame('ready', $updated->status);
        $this->assertSame(['https://drive.google.com/file/d/photo/view'], $updated->image_drive_urls);
    }

    public function test_empty_image_drive_urls_field_clears_existing_values(): void
    {
        $post = Post::factory()->create([
            'title' => 'Old title',
            'image_drive_urls' => ['https://drive.google.com/file/d/photo/view'],
        ]);

        $request = UpdatePostRequest::create('/posts/1', 'PATCH', [
            'title' => 'Old title',
            'image_drive_urls' => '',
        ]);

        $updated = (new UpdatePostAction)->handle($post, UpdatePostData::fromRequest($request));

        $this->assertNull($updated->image_drive_urls);
    }

    public function test_filled_image_drive_urls_textarea_replaces_existing_values(): void
    {
        $post = Post::factory()->create([
            'title' => 'Old title',
            'image_drive_urls' => ['https://drive.google.com/file/d/old/view'],
        ]);

        $request = UpdatePostRequest::create('/posts/1', 'PATCH', [
            'title' => 'Old title',
            'image_drive_urls' => "https://drive.google.com/file/d/one/view\nhttps://drive.google.com/file/d/two/view",
        ]);

        $updated = (new UpdatePostAction)->handle($post, UpdatePostData::fromRequest($request));

        $this->assertSame([
            'https://drive.google.com/file/d/one/view',
            'https://drive.google.com/file/d/two/view',
        ], $updated->image_drive_urls);
    }

    public function test_api_payload_updates_captions_without_wiping_image_drive_urls(): void
    {
        $post = Post::factory()->create([
            'title' => 'Old title',
            'body' => 'Old body.',
            'language' => 'bn',
            'slug' => 'old-slug',
            'captions' => ['facebook' => 'old caption'],
            'platforms' => ['facebook'],
            'image_drive_urls' => ['https://drive.google.com/file/d/photo/view'],
        ]);

        $updated = (new UpdatePostAction)->handle($post, UpdatePostData::fromApiPayload([
            'title' => 'New title',
            'captions' => ['facebook' => 'new caption'],
        ], $post));

        $this->assertSame('New title', $updated->title);
        $this->assertSame(['facebook' => 'new caption'], $updated->captions);
        $this->assertSame('bn', $updated->language);
        $this->assertSame('old-slug', $updated->slug);
        $this->assertSame(['facebook'], $updated->platforms);
        $this->assertSame(['https://drive.google.com/file/d/photo/view'], $updated->image_drive_urls);
    }

    public function test_api_payload_can_store_postsyncer_groups_without_wiping_captions(): void
    {
        $post = Post::factory()->create([
            'title' => 'Scheduled post',
            'captions' => ['facebook' => 'keep me'],
            'status' => 'scheduled',
        ]);

        $groups = [
            'groups' => [[
                'post_id' => '132531',
                'status' => 'SCHEDULED',
                'scheduled_at' => '2026-08-26T21:18:00+06:00',
                'platforms' => ['facebook'],
                'language' => 'bangla',
            ]],
        ];

        $updated = (new UpdatePostAction)->handle($post, UpdatePostData::fromApiPayload([
            'postsyncer' => $groups,
        ], $post));

        $this->assertSame($groups, $updated->postsyncer);
        $this->assertSame(['facebook' => 'keep me'], $updated->captions);
        $this->assertSame('scheduled', $updated->status);
    }
}
