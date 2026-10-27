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

    public function test_blocked_status_keeps_its_reason_until_the_post_leaves_blocked(): void
    {
        $post = Post::factory()->create(['title' => 'Stuck', 'status' => 'draft']);

        (new UpdatePostAction)->handle($post, UpdatePostData::fromApiPayload([
            'status' => 'blocked',
            'blocked_reason' => 'Scheduler cannot publish carousels.',
        ], $post));

        $post->refresh();
        $this->assertSame('blocked', $post->status);
        $this->assertSame('Scheduler cannot publish carousels.', $post->blocked_reason);

        (new UpdatePostAction)->handle($post, UpdatePostData::fromApiPayload(['title' => 'Still stuck'], $post));

        $this->assertSame('Scheduler cannot publish carousels.', $post->refresh()->blocked_reason);

        (new UpdatePostAction)->handle($post, UpdatePostData::fromApiPayload(['status' => 'draft'], $post));

        $post->refresh();
        $this->assertSame('draft', $post->status);
        $this->assertNull($post->blocked_reason);
    }

    public function test_a_publish_locked_post_with_only_terminal_groups_can_be_blocked_without_touching_its_groups(): void
    {
        $groups = [
            ['post_id' => '99', 'platforms' => ['facebook'], 'status' => 'PUBLISHED'],
            ['post_id' => '100', 'platforms' => ['youtube'], 'status' => 'FAILED'],
        ];
        $post = Post::factory()->create([
            'status' => 'posted',
            'publish_state' => 'failed',
            'publish_error' => 'YouTube refused the upload.',
            'postsyncer' => ['groups' => $groups],
            'publish_progress' => ['state' => 'failed', 'current' => null, 'completed_groups' => []],
        ]);
        $before = $post->only(['postsyncer', 'publish_state', 'publish_error', 'publish_progress', 'captions', 'platforms']);

        (new UpdatePostAction)->handle($post, UpdatePostData::fromApiPayload([
            'status' => 'blocked',
            'blocked_reason' => 'YouTube scheduler limit; revisit later.',
        ], $post));

        $post->refresh();
        $this->assertSame('blocked', $post->status);
        $this->assertSame('YouTube scheduler limit; revisit later.', $post->blocked_reason);
        $this->assertEquals($before, $post->only(array_keys($before)));
    }

    public function test_a_publish_locked_post_with_a_pending_group_or_publish_in_flight_cannot_be_blocked(): void
    {
        $cases = [
            'scheduled group' => [['post_id' => '99', 'status' => 'SCHEDULED']],
            'in-queue group' => [['post_id' => '99', 'status' => 'IN_QUEUE']],
            'pending group' => [['post_id' => '99', 'status' => 'PENDING']],
            'in-progress group' => [['post_id' => '99', 'status' => 'IN_PROGRESS']],
            'group without a status' => [['post_id' => '99']],
            'published and pending groups' => [
                ['post_id' => '99', 'status' => 'PUBLISHED'],
                ['post_id' => '100', 'status' => 'SCHEDULED'],
            ],
        ];

        foreach ($cases as $label => $groups) {
            $post = Post::factory()->create([
                'status' => 'scheduled',
                'postsyncer' => ['groups' => $groups],
            ]);

            try {
                (new UpdatePostAction)->handle($post, UpdatePostData::fromApiPayload([
                    'status' => 'blocked',
                    'blocked_reason' => 'Too early.',
                ], $post));
                $this->fail("Expected [{$label}] to be refused.");
            } catch (ValidationException) {
                $this->assertSame('scheduled', $post->refresh()->status, $label);
            }
        }

        $inFlight = [
            'publish_state queued' => ['publish_state' => 'queued', 'publish_progress' => null],
            'publish_state running' => ['publish_state' => 'running', 'publish_progress' => null],
            'progress running' => ['publish_state' => 'failed', 'publish_progress' => ['state' => 'running', 'current' => ['phase' => 'create']]],
            'progress queued' => ['publish_state' => 'failed', 'publish_progress' => ['state' => 'queued', 'current' => null]],
            'progress uncertain' => ['publish_state' => 'failed', 'publish_progress' => ['state' => 'uncertain', 'current' => ['phase' => 'create']]],
        ];

        foreach ($inFlight as $label => $attributes) {
            $post = Post::factory()->create(array_merge([
                'status' => 'posted',
                'postsyncer' => ['groups' => [['post_id' => '99', 'status' => 'PUBLISHED']]],
            ], $attributes));

            try {
                (new UpdatePostAction)->handle($post, UpdatePostData::fromApiPayload([
                    'status' => 'blocked',
                    'blocked_reason' => 'Too early.',
                ], $post));
                $this->fail("Expected [{$label}] to be refused.");
            } catch (ValidationException) {
                $this->assertSame('posted', $post->refresh()->status, $label);
            }
        }
    }

    public function test_lowercase_group_statuses_and_a_checkpoint_only_failure_count_as_settled(): void
    {
        $lowercase = Post::factory()->create([
            'status' => 'posted',
            'postsyncer' => ['groups' => [['post_id' => '99', 'status' => 'published'], ['post_id' => '100', 'status' => 'failed']]],
        ]);

        (new UpdatePostAction)->handle($lowercase, UpdatePostData::fromApiPayload(['status' => 'blocked'], $lowercase));
        $this->assertSame('blocked', $lowercase->refresh()->status);

        $checkpointOnly = Post::factory()->create([
            'status' => 'posted',
            'publish_state' => 'failed',
            'postsyncer' => null,
            'publish_progress' => ['state' => 'failed', 'current' => ['phase' => 'create'], 'completed_groups' => []],
        ]);

        (new UpdatePostAction)->handle($checkpointOnly, UpdatePostData::fromApiPayload(['status' => 'blocked'], $checkpointOnly));
        $this->assertSame('blocked', $checkpointOnly->refresh()->status);
    }

    public function test_a_group_with_an_array_status_is_not_settled(): void
    {
        $post = Post::factory()->create([
            'status' => 'scheduled',
            'postsyncer' => ['groups' => [['post_id' => '99', 'status' => ['PUBLISHED']]]],
        ]);

        $this->expectException(ValidationException::class);

        (new UpdatePostAction)->handle($post, UpdatePostData::fromApiPayload(['status' => 'blocked'], $post));
    }

    public function test_a_blocked_post_with_groups_only_leaves_for_posted_or_archived(): void
    {
        $groups = [['post_id' => '99', 'status' => 'PUBLISHED']];
        $post = Post::factory()->create([
            'status' => 'blocked',
            'blocked_reason' => 'Scheduler limit.',
            'postsyncer' => ['groups' => $groups],
        ]);

        foreach (['draft', 'scheduled'] as $refused) {
            try {
                (new UpdatePostAction)->handle($post, UpdatePostData::fromApiPayload(['status' => $refused], $post));
                $this->fail("Expected a move to [{$refused}] to be refused.");
            } catch (ValidationException) {
                $this->assertSame('blocked', $post->refresh()->status);
            }
        }

        (new UpdatePostAction)->handle($post, UpdatePostData::fromApiPayload(['status' => 'posted'], $post));

        $post->refresh();
        $this->assertSame('posted', $post->status);
        $this->assertNull($post->blocked_reason);
        $this->assertEquals($groups, $post->postsyncer['groups']);
    }

    public function test_a_blocked_post_with_a_pending_group_cannot_be_unblocked(): void
    {
        $post = Post::factory()->create([
            'status' => 'blocked',
            'blocked_reason' => 'Scheduler limit.',
            'postsyncer' => ['groups' => [['post_id' => '99', 'status' => 'SCHEDULED']]],
        ]);

        $this->expectException(ValidationException::class);

        (new UpdatePostAction)->handle($post, UpdatePostData::fromApiPayload(['status' => 'archived'], $post));
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

    /**
     * @return array<string, mixed>
     */
    private function scheduledBilingualPost(): array
    {
        return [
            'title' => 'Scheduled carousel',
            'body' => 'Body.',
            'status' => 'scheduled',
            'approval_state' => 'approved',
            'publish_state' => 'succeeded',
            'platforms' => ['facebook', 'instagram', 'twitter'],
            'captions' => [
                'Bangla' => [
                    'facebook' => ['title' => '', 'caption' => 'বাংলা', 'first_comment' => '', 'images' => ['bn-1.png'], 'thread' => []],
                ],
                'English' => [
                    'facebook' => ['title' => '', 'caption' => 'English', 'first_comment' => '', 'images' => ['en-1.png'], 'thread' => []],
                    'twitter' => ['title' => '', 'caption' => 'short', 'first_comment' => '', 'images' => [], 'thread' => []],
                ],
            ],
            'postsyncer' => ['groups' => [
                ['post_id' => '101', 'status' => 'SCHEDULED', 'language' => 'bangla', 'platforms' => ['facebook']],
                ['post_id' => '102', 'status' => 'SCHEDULED', 'language' => 'english', 'platforms' => ['facebook', 'twitter']],
            ]],
            'publish_progress' => ['state' => 'succeeded', 'current' => null, 'completed_groups' => [
                ['index' => 0, 'platforms' => ['instagram']],
            ]],
        ];
    }

    public function test_a_scheduled_post_can_gain_a_caption_section_for_an_unpublished_platform(): void
    {
        $post = Post::factory()->create($this->scheduledBilingualPost());
        $captions = $post->captions;
        // Re-sent sections arrive in a different key order, as jsonb returns them.
        $captions['English'] = array_reverse($captions['English'], true);
        $captions['English']['facebook'] = array_reverse($captions['English']['facebook'], true);
        $captions['English']['linkedin'] = ['title' => '', 'caption' => 'LinkedIn teaser', 'first_comment' => '', 'images' => ['p.pdf'], 'thread' => []];

        (new UpdatePostAction)->handle($post, UpdatePostData::fromApiPayload([
            'captions' => $captions,
            'platforms' => ['facebook', 'instagram', 'linkedin', 'twitter'],
        ], $post));

        $post->refresh();
        $this->assertSame('LinkedIn teaser', $post->captions['English']['linkedin']['caption']);
        $this->assertSame(['facebook', 'instagram', 'linkedin', 'twitter'], $post->platforms);
        $this->assertSame('pending', $post->approval_state);
        $this->assertSame('succeeded', $post->publish_state);
        $this->assertSame('102', $post->postsyncer['groups'][1]['post_id']);
    }

    public function test_a_scheduled_post_cannot_change_an_existing_caption_section(): void
    {
        $post = Post::factory()->create($this->scheduledBilingualPost());
        $captions = $post->captions;
        $captions['English']['twitter']['caption'] = 'edited';
        $captions['English']['linkedin'] = ['caption' => 'LinkedIn teaser'];

        $this->assertLockedUpdateRefused($post, ['captions' => $captions]);
    }

    public function test_a_scheduled_post_cannot_add_a_section_for_a_platform_already_in_a_group(): void
    {
        foreach (['twitter' => 'Bangla', 'instagram' => 'English'] as $platform => $part) {
            $post = Post::factory()->create($this->scheduledBilingualPost());
            $captions = $post->captions;
            $captions[$part][$platform] = ['caption' => 'new'];

            $this->assertLockedUpdateRefused($post, ['captions' => $captions]);
        }
    }

    public function test_a_scheduled_post_cannot_lose_a_platform_or_a_section(): void
    {
        $post = Post::factory()->create($this->scheduledBilingualPost());
        $this->assertLockedUpdateRefused($post, ['platforms' => ['facebook', 'twitter', 'linkedin']]);

        $captions = $post->captions;
        unset($captions['English']['twitter']);
        $captions['English']['linkedin'] = ['caption' => 'LinkedIn teaser'];
        $this->assertLockedUpdateRefused($post, ['captions' => $captions]);
    }

    public function test_a_scheduled_post_cannot_change_other_content_alongside_an_added_section(): void
    {
        $post = Post::factory()->create($this->scheduledBilingualPost());
        $captions = $post->captions;
        $captions['English']['linkedin'] = ['caption' => 'LinkedIn teaser'];

        $this->assertLockedUpdateRefused($post, ['captions' => $captions, 'title' => 'Renamed']);
    }

    public function test_a_scheduled_post_cannot_change_its_caption_map_shape(): void
    {
        $post = Post::factory()->create($this->scheduledBilingualPost());
        $captions = $post->captions;
        $captions['linkedin'] = ['caption' => 'flat section beside language parts'];
        $this->assertLockedUpdateRefused($post, ['captions' => $captions]);

        $flat = Post::factory()->create(array_merge($this->scheduledBilingualPost(), [
            'captions' => ['facebook' => ['caption' => 'fb'], 'twitter' => ['caption' => 'x']],
        ]));
        $this->assertLockedUpdateRefused($flat, ['captions' => $flat->captions + ['English' => ['linkedin' => ['caption' => 'nested']]]]);
        $this->assertLockedUpdateRefused($flat, ['captions' => ['facebook' => ['caption' => 'fb', 'title' => 'new'], 'twitter' => ['caption' => 'x']]]);

        $flat->captions = $flat->captions + ['linkedin' => ['caption' => 'li']];
        (new UpdatePostAction)->handle($flat, UpdatePostData::fromApiPayload(['captions' => $flat->captions], $flat->fresh()));
        $this->assertSame('li', $flat->refresh()->captions['linkedin']['caption']);
    }

    public function test_a_scheduled_post_cannot_add_non_platform_keys(): void
    {
        foreach (['caption', 'title', 'name', 'platforms', 'mastodon'] as $key) {
            $post = Post::factory()->create($this->scheduledBilingualPost());
            $captions = $post->captions;
            $captions['English'][$key] = ['caption' => 'sneaky'];

            $this->assertLockedUpdateRefused($post, ['captions' => $captions]);
        }

        $post = Post::factory()->create($this->scheduledBilingualPost());
        $this->assertLockedUpdateRefused($post, ['captions' => $post->captions + ['Spanish' => ['title' => 'x', 'caption' => 'y']]]);
    }

    public function test_re_sending_identical_captions_in_another_key_order_keeps_approval(): void
    {
        $post = Post::factory()->create($this->scheduledBilingualPost());
        $captions = array_reverse($post->captions, true);

        (new UpdatePostAction)->handle($post, UpdatePostData::fromApiPayload(['captions' => $captions], $post));

        $this->assertSame('approved', $post->refresh()->approval_state);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertLockedUpdateRefused(Post $post, array $payload): void
    {
        $before = $post->only(['title', 'captions', 'platforms', 'approval_state']);

        try {
            (new UpdatePostAction)->handle($post, UpdatePostData::fromApiPayload($payload, $post));
            $this->fail('Expected the publish-locked update to be refused.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('after a PostSyncer publish attempt', $exception->getMessage());
        }

        $this->assertEquals($before, $post->refresh()->only(array_keys($before)));
    }
}
