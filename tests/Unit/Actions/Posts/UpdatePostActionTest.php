<?php

namespace Tests\Unit\Actions\Posts;

use App\Actions\Posts\UpdatePostAction;
use App\Data\Posts\UpdatePostData;
use App\Http\Requests\Posts\UpdatePostRequest;
use App\Models\Post;
use App\Models\TelegramBotConfig;
use App\Models\TelegramPostRequest;
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

    public function test_status_only_patch_keeps_existing_body_and_approval(): void
    {
        $post = Post::factory()->create([
            'title' => 'Keep title',
            'body' => 'Keep body.',
            'approval_state' => 'approved',
            'status' => 'draft',
        ]);

        $request = UpdatePostRequest::create('/posts/1', 'PATCH', [
            'title' => 'Keep title',
            'status' => 'ready',
        ]);

        (new UpdatePostAction)->handle($post, UpdatePostData::fromRequest($request));

        $post->refresh();
        $this->assertSame('Keep body.', $post->body);
        $this->assertSame('approved', $post->approval_state);
    }

    public function test_editing_captions_requires_approval_again(): void
    {
        $post = Post::factory()->create([
            'approval_state' => 'approved',
            'captions' => ['facebook' => ['caption' => 'Old caption']],
        ]);
        $config = TelegramBotConfig::factory()->for($post->workspace)->create();
        $telegramRequest = TelegramPostRequest::factory()->for($post->workspace)->create([
            'telegram_bot_config_id' => $config->id,
            'post_id' => $post->id,
            'state' => TelegramPostRequest::APPROVED,
        ]);

        $request = UpdatePostRequest::create('/posts/1', 'PATCH', [
            'title' => $post->title,
            'body' => $post->body,
            'captions' => ['facebook' => ['caption' => 'New caption']],
        ]);

        (new UpdatePostAction)->handle($post, UpdatePostData::fromRequest($request));

        $post->refresh();
        $this->assertSame(['facebook' => ['caption' => 'New caption']], $post->captions);
        $this->assertSame('pending', $post->approval_state);
        $this->assertNull($post->approved_at);
        $this->assertNull($post->approved_by_user_id);
        $this->assertSame(TelegramPostRequest::AWAITING_APPROVAL, $telegramRequest->refresh()->state);
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

    public function test_a_checkpointed_publish_cannot_have_its_metadata_overwritten(): void
    {
        $post = Post::factory()->create([
            'publish_state' => 'failed',
            'publish_progress' => [
                'version' => 1,
                'operation_id' => 'operation-1',
                'options' => [],
                'plan_hash' => 'plan-1',
                'planned_groups' => [['index' => 0, 'group_key' => 'group-1']],
                'completed_groups' => [],
                'current' => [
                    'index' => 0,
                    'group_key' => 'group-1',
                    'phase' => 'creating',
                    'idempotency_key' => 'idempotency-1',
                    'media_ids' => [],
                ],
                'state' => 'uncertain',
            ],
        ]);

        $this->expectException(ValidationException::class);

        (new UpdatePostAction)->handle($post, UpdatePostData::fromApiPayload([
            'publish_state' => 'idle',
            'publish_error' => null,
        ], $post));
    }

    public function test_a_deterministic_failure_can_be_retried_as_a_new_operation_after_an_edit(): void
    {
        $post = Post::factory()->create([
            'title' => 'Old title',
            'captions' => ['facebook' => 'Old caption'],
            'publish_state' => 'failed',
            'publish_error' => 'No account id mapped.',
            'publish_progress' => [
                'version' => 1,
                'operation_id' => 'operation-1',
                'options' => [],
                'plan_hash' => 'plan-1',
                'planned_groups' => [],
                'completed_groups' => [],
                'current' => null,
                'state' => 'failed',
            ],
        ]);

        (new UpdatePostAction)->handle($post, UpdatePostData::fromApiPayload([
            'title' => 'New title',
            'captions' => ['facebook' => 'New caption'],
        ], $post));

        $post->refresh();
        $this->assertSame('New title', $post->title);
        $this->assertSame('idle', $post->publish_state);
        $this->assertNull($post->publish_error);
        $this->assertNull($post->publish_progress);
        $this->assertSame('pending', $post->approval_state);
    }

    public function test_published_posts_can_be_archived_and_unarchived(): void
    {
        $post = Post::factory()->create([
            'title' => 'Published post',
            'status' => 'posted',
            'publish_state' => 'succeeded',
            'postsyncer' => ['groups' => [['post_id' => '99']]],
            'publish_progress' => ['completed_groups' => [['post_id' => '99']]],
        ]);

        (new UpdatePostAction)->handle($post, new UpdatePostData(
            title: $post->title,
            status: 'archived',
            hasBody: false,
        ));

        $post->refresh();
        $this->assertSame('archived', $post->status);

        (new UpdatePostAction)->handle($post, new UpdatePostData(
            title: $post->title,
            status: 'posted',
            hasBody: false,
        ));

        $this->assertSame('posted', $post->refresh()->status);
    }
}
