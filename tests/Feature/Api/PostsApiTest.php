<?php

namespace Tests\Feature\Api;

use App\Actions\ApiTokens\CreateWorkspaceApiTokenAction;
use App\Actions\Postsyncer\PublishPostAction;
use App\Data\ApiTokens\CreateWorkspaceApiTokenData;
use App\Jobs\PublishPostJob;
use App\Models\Attachment;
use App\Models\Idea;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Postsyncer\PostsyncerException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PostsApiTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create();
        $this->token = (new CreateWorkspaceApiTokenAction)->handle(
            $this->workspace,
            User::factory()->create(),
            new CreateWorkspaceApiTokenData('test client'),
        )['plaintext'];
    }

    private function acting(): self
    {
        return $this->withToken($this->token);
    }

    public function test_store_imports_a_post_with_explicit_human_id(): void
    {
        $this->acting()->postJson('/api/v1/posts', [
            'human_id' => 'BP-12',
            'number' => 12,
            'title' => 'Open weights meme',
            'language' => 'bn',
            'body' => 'caption body',
            'platforms' => ['facebook', 'instagram'],
            'captions' => ['facebook' => 'fb text'],
            'status' => 'posted',
        ])
            ->assertCreated()
            ->assertJsonPath('data.human_id', 'BP-12')
            ->assertJsonPath('data.platforms.0', 'facebook');

        $this->acting()->patchJson('/api/v1/posts/BP-12', [
            'status' => 'archived',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');
    }

    public function test_an_explicit_import_advances_the_generated_post_sequence(): void
    {
        $this->acting()->postJson('/api/v1/posts', [
            'human_id' => 'BP-1',
            'number' => 1,
            'title' => 'Imported post',
        ])->assertCreated();

        $this->acting()->postJson('/api/v1/posts', [
            'title' => 'Generated post',
        ])
            ->assertCreated()
            ->assertJsonPath('data.human_id', 'P-2');
    }

    public function test_store_rejects_an_idea_from_another_workspace(): void
    {
        $foreignIdea = Idea::factory()->for(Workspace::factory())->create();

        $this->acting()->postJson('/api/v1/posts', [
            'title' => 'Cross-workspace post',
            'idea_id' => $foreignIdea->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idea_id');

        $this->assertDatabaseMissing('posts', ['title' => 'Cross-workspace post']);
    }

    public function test_a_write_only_token_does_not_receive_the_post_body(): void
    {
        $writeToken = (new CreateWorkspaceApiTokenAction)->handle(
            $this->workspace,
            User::factory()->create(),
            new CreateWorkspaceApiTokenData('write-only', ['posts:write']),
        )['plaintext'];

        $this->withToken($writeToken)->postJson('/api/v1/posts', [
            'title' => 'Private post',
            'body' => 'Private body',
        ])
            ->assertCreated()
            ->assertJsonMissingPath('data.body')
            ->assertJsonMissingPath('data.captions');
    }

    public function test_index_omits_heavy_fields_by_default(): void
    {
        Post::factory()->for($this->workspace)->create([
            'human_id' => 'P-57',
            'number' => 57,
            'approval_state' => 'pending',
            'body' => str_repeat('paragraph ', 80),
            'captions' => ['facebook' => str_repeat('caption ', 40)],
            'template' => 'C',
        ]);

        $this->acting()->getJson('/api/v1/posts')
            ->assertOk()
            ->assertJsonPath('data.0.human_id', 'P-57')
            ->assertJsonPath('data.0.approval_state', 'pending')
            ->assertJsonPath('data.0.has_body', true)
            ->assertJsonPath('data.0.has_captions', true)
            ->assertJsonPath('data.0.template', 'C')
            ->assertJsonMissingPath('data.0.body')
            ->assertJsonMissingPath('data.0.captions');
    }

    public function test_index_include_full_returns_heavy_fields(): void
    {
        Post::factory()->for($this->workspace)->create([
            'human_id' => 'P-58',
            'number' => 58,
            'body' => 'full body',
            'captions' => ['instagram' => 'full cap'],
        ]);

        $this->acting()->getJson('/api/v1/posts?include=full')
            ->assertOk()
            ->assertJsonPath('data.0.body', 'full body')
            ->assertJsonPath('data.0.captions.instagram', 'full cap');
    }

    public function test_index_exposes_publish_progress_for_reconciliation(): void
    {
        Post::factory()->for($this->workspace)->create([
            'human_id' => 'P-59',
            'number' => 59,
            'publish_state' => 'failed',
            'publish_progress' => ['state' => 'uncertain'],
        ]);

        $this->acting()->getJson('/api/v1/posts')
            ->assertOk()
            ->assertJsonPath('data.0.publish_progress.state', 'uncertain');
    }

    public function test_update_cannot_forge_postsyncer_groups(): void
    {
        $this->acting()->postJson('/api/v1/posts', [
            'human_id' => 'P-51',
            'number' => 51,
            'title' => 'Scheduled meme',
            'status' => 'scheduled',
        ])->assertCreated();

        $this->acting()->patchJson('/api/v1/posts/P-51', [
            'postsyncer' => [
                'groups' => [[
                    'post_id' => '132531',
                    'status' => 'SCHEDULED',
                    'scheduled_at' => '2026-08-26T21:18:00+06:00',
                    'platforms' => ['facebook'],
                    'language' => 'bangla',
                ]],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('postsyncer');

        $this->assertNull(Post::query()->where('human_id', 'P-51')->value('postsyncer'));
    }

    public function test_update_cannot_forge_publish_state_or_error(): void
    {
        $this->acting()->postJson('/api/v1/posts', [
            'human_id' => 'P-57',
            'number' => 57,
            'title' => 'Already live',
            'status' => 'posted',
        ])->assertCreated();

        Post::query()->where('human_id', 'P-57')->update([
            'publish_state' => 'failed',
            'publish_error' => 'This post already has PostSyncer posts. Republish is not supported yet.',
        ]);

        $this->acting()->patchJson('/api/v1/posts/P-57', [
            'publish_state' => 'succeeded',
            'publish_error' => null,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('publish_state');

        $this->assertSame('failed', Post::query()->where('human_id', 'P-57')->value('publish_state'));
    }

    public function test_patch_accepts_image_drive_urls(): void
    {
        $this->fakeAccessibleDriveLinks();

        $this->acting()->postJson('/api/v1/posts', [
            'human_id' => 'P-52',
            'number' => 52,
            'title' => 'Photo post',
            'status' => 'draft',
        ])->assertCreated();

        $this->acting()->patchJson('/api/v1/posts/P-52', [
            'image_drive_urls' => ['https://drive.google.com/file/d/photoOne/view'],
        ])
            ->assertOk()
            ->assertJsonPath('data.image_drive_urls.0', 'https://drive.google.com/file/d/photoOne/view');
    }

    public function test_patch_sets_blocked_with_a_reason_and_clears_it_on_unblock(): void
    {
        $this->acting()->postJson('/api/v1/posts', [
            'human_id' => 'P-60',
            'number' => 60,
            'title' => 'Carousel',
            'status' => 'draft',
        ])->assertCreated();

        $this->acting()->patchJson('/api/v1/posts/P-60', [
            'status' => 'blocked',
            'blocked_reason' => 'Scheduler cannot publish video carousels.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'blocked')
            ->assertJsonPath('data.blocked_reason', 'Scheduler cannot publish video carousels.');

        $this->acting()->patchJson('/api/v1/posts/P-60', ['status' => 'draft'])
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.blocked_reason', null);
    }

    public function test_patch_blocks_a_publish_locked_post_and_leaves_its_groups_alone(): void
    {
        $groups = [
            ['post_id' => '501', 'platforms' => ['facebook', 'instagram'], 'status' => 'PUBLISHED'],
            ['post_id' => '502', 'platforms' => ['youtube'], 'status' => 'FAILED'],
        ];
        Post::factory()->for($this->workspace)->create([
            'human_id' => 'P-62',
            'number' => 62,
            'status' => 'posted',
            'postsyncer' => ['groups' => $groups],
            'publish_state' => 'succeeded',
        ]);

        $this->acting()->patchJson('/api/v1/posts/P-62', [
            'status' => 'blocked',
            'blocked_reason' => 'YouTube scheduler limit; revisit later.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'blocked')
            ->assertJsonPath('data.blocked_reason', 'YouTube scheduler limit; revisit later.');

        $post = Post::query()->where('human_id', 'P-62')->firstOrFail();
        $this->assertEquals($groups, $post->postsyncer['groups']);
        $this->assertSame('succeeded', $post->publish_state);

        $this->acting()->patchJson('/api/v1/posts/P-62', ['status' => 'draft'])
            ->assertUnprocessable();
        $this->assertSame('blocked', $post->refresh()->status);

        $this->acting()->patchJson('/api/v1/posts/P-62', ['status' => 'posted'])
            ->assertOk()
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.blocked_reason', null);
        $this->assertEquals($groups, $post->refresh()->postsyncer['groups']);
    }

    public function test_patch_refuses_the_retired_dropped_status(): void
    {
        $this->acting()->postJson('/api/v1/posts', [
            'human_id' => 'P-61',
            'number' => 61,
            'title' => 'Old idea',
            'status' => 'draft',
        ])->assertCreated();

        $this->acting()->patchJson('/api/v1/posts/P-61', ['status' => 'dropped'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_category_defaults_validates_and_does_not_reset_approval(): void
    {
        $this->acting()->postJson('/api/v1/posts', ['human_id' => 'P-90', 'number' => 90, 'title' => 'Plain'])
            ->assertCreated()
            ->assertJsonPath('data.category', 'default');

        $this->acting()->postJson('/api/v1/posts', ['human_id' => 'P-91', 'number' => 91, 'title' => 'Morning', 'category' => 'morning'])
            ->assertCreated()
            ->assertJsonPath('data.category', 'morning');

        $this->acting()->postJson('/api/v1/posts', ['title' => 'Bad', 'category' => 'night'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category');

        $this->acting()->patchJson('/api/v1/posts/P-91', ['category' => 'night'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category');

        $this->assertSame('approved', Post::query()->where('human_id', 'P-91')->value('approval_state'));

        $this->acting()->patchJson('/api/v1/posts/P-91', ['category' => 'evening_carousel'])
            ->assertOk()
            ->assertJsonPath('data.category', 'evening_carousel')
            ->assertJsonPath('data.approval_state', 'approved');
    }

    public function test_store_and_patch_accept_template_letter(): void
    {
        $this->acting()->postJson('/api/v1/posts', [
            'human_id' => 'P-63',
            'number' => 63,
            'title' => 'Database pairs',
            'status' => 'draft',
            'template' => 'd',
        ])
            ->assertCreated()
            ->assertJsonPath('data.template', 'D');

        $this->acting()->patchJson('/api/v1/posts/P-63', [
            'template' => 'E',
        ])
            ->assertOk()
            ->assertJsonPath('data.template', 'E');

        $this->assertSame('E', Post::query()->where('human_id', 'P-63')->value('template'));
    }

    public function test_store_and_patch_accept_active_g_and_h_catalog_templates(): void
    {
        $this->acting()->postJson('/api/v1/posts', [
            'human_id' => 'P-66',
            'number' => 66,
            'title' => 'DNS lookup',
            'status' => 'draft',
            'template' => 'g',
        ])
            ->assertCreated()
            ->assertJsonPath('data.template', 'G');

        $this->acting()->patchJson('/api/v1/posts/P-66', [
            'template' => 'H',
        ])
            ->assertOk()
            ->assertJsonPath('data.template', 'H');

        $this->assertSame('H', Post::query()->where('human_id', 'P-66')->value('template'));
    }

    public function test_index_lists_posts(): void
    {
        Post::factory()->for($this->workspace)->create(['human_id' => 'P-1', 'number' => 1]);
        Post::factory()->for($this->workspace)->create(['human_id' => 'P-2', 'number' => 2]);

        $this->acting()->getJson('/api/v1/posts')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_upload_image_attaches_the_file_and_streams_it_back(): void
    {
        Storage::fake('scratchpad');

        $this->acting()->postJson('/api/v1/posts', [
            'human_id' => 'BP-12',
            'number' => 12,
            'title' => 'Open weights meme',
        ])->assertCreated();

        $this->acting()->post('/api/v1/posts/BP-12/images', [
            'image' => UploadedFile::fake()->image('cover.png', 10, 10),
        ])
            ->assertCreated()
            ->assertJsonPath('data.human_id', 'BP-12')
            ->assertJsonPath('data.attachments.0.role', 'image');

        $asset = MediaAsset::query()->sole();
        Storage::disk('scratchpad')->assertExists($asset->path);
        $this->assertSame('cover.png', $asset->original_filename);

        $this->acting()->get("/api/v1/posts/BP-12/media/{$asset->id}")
            ->assertOk()
            ->assertHeader('Content-Type', $asset->mime);
    }

    public function test_reuploading_the_same_image_to_a_post_is_idempotent(): void
    {
        Storage::fake('scratchpad');

        $this->acting()->postJson('/api/v1/posts', [
            'human_id' => 'P-3',
            'number' => 3,
            'title' => 'Same file twice',
        ])->assertCreated();

        $file = UploadedFile::fake()->image('cover.png', 12, 12);

        $this->acting()->post('/api/v1/posts/P-3/images', ['image' => $file])->assertCreated();
        $this->acting()->post('/api/v1/posts/P-3/images', [
            'image' => UploadedFile::fake()->image('cover.png', 12, 12),
        ])->assertOk();

        $this->assertSame(1, MediaAsset::query()->count());
        $this->assertSame(1, Attachment::query()->count());
    }

    public function test_media_of_another_workspace_is_not_found(): void
    {
        Storage::fake('scratchpad');

        $this->acting()->postJson('/api/v1/posts', [
            'human_id' => 'P-4',
            'number' => 4,
            'title' => 'Mine',
        ])->assertCreated();

        $foreign = MediaAsset::factory()->create([
            'disk' => 'scratchpad',
            'mime' => 'image/png',
        ]);
        Storage::disk('scratchpad')->put($foreign->path, 'bytes');

        $this->acting()->get("/api/v1/posts/P-4/media/{$foreign->id}")
            ->assertNotFound();
    }

    public function test_an_unattached_asset_in_the_same_workspace_is_not_found(): void
    {
        Storage::fake('scratchpad');

        $this->acting()->postJson('/api/v1/posts', [
            'human_id' => 'P-5',
            'number' => 5,
            'title' => 'Mine',
        ])->assertCreated();

        $asset = MediaAsset::factory()->for($this->workspace)->create([
            'disk' => 'scratchpad',
            'mime' => 'image/png',
        ]);
        Storage::disk('scratchpad')->put($asset->path, 'bytes');

        $this->acting()->get("/api/v1/posts/P-5/media/{$asset->id}")
            ->assertNotFound();
    }

    public function test_upload_pdf_attaches_a_linkedin_document_and_streams_it_back(): void
    {
        Storage::fake('scratchpad');

        $this->acting()->postJson('/api/v1/posts', [
            'human_id' => 'P-50',
            'number' => 50,
            'title' => 'N+1',
        ])->assertCreated();

        $pdf = UploadedFile::fake()->createWithContent(
            'p-50-linkedin-carousel.pdf',
            "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n",
        );

        $this->acting()->post('/api/v1/posts/P-50/documents', [
            'document' => $pdf,
        ])
            ->assertCreated()
            ->assertJsonPath('data.human_id', 'P-50')
            ->assertJsonPath('data.attachments.0.role', 'document')
            ->assertJsonPath('data.attachments.0.filename', 'p-50-linkedin-carousel.pdf');

        $asset = MediaAsset::query()->sole();
        $this->assertSame('document', $asset->kind);
        Storage::disk('scratchpad')->assertExists($asset->path);

        $this->acting()->get("/api/v1/posts/P-50/media/{$asset->id}")
            ->assertOk()
            ->assertHeader('Content-Type', $asset->mime);
    }

    private function mp4(string $name, int $kilobytes = 1): UploadedFile
    {
        // An ftyp box on a real file, so the content-sniffed mimetypes rule sees MP4.
        // Laravel's fake upload guesses its mime from the name and misses this.
        $header = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom";
        $path = tempnam(sys_get_temp_dir(), 'cm-mp4').'.mp4';
        file_put_contents($path, $header.str_repeat("\0", max(0, $kilobytes * 1024 - strlen($header))));

        return new UploadedFile($path, $name, 'video/mp4', null, true);
    }

    public function test_upload_motion_video_attaches_an_mp4_slide_for_one_platform_and_language(): void
    {
        Storage::fake('scratchpad');

        $this->acting()->postJson('/api/v1/posts', [
            'human_id' => 'P-60',
            'number' => 60,
            'title' => 'Motion carousel',
        ])->assertCreated();

        $this->acting()->post('/api/v1/posts/P-60/videos', [
            'file' => $this->mp4('upload-name.mp4'),
            'filename' => 'slide-1.mp4',
            'platform' => 'instagram',
            'language' => 'en',
            'order' => 3,
        ])
            ->assertCreated()
            ->assertJsonPath('data.attachments.0.role', 'motion')
            ->assertJsonPath('data.attachments.0.platform', 'instagram')
            ->assertJsonPath('data.attachments.0.language', 'en')
            ->assertJsonPath('data.attachments.0.filename', 'slide-1.mp4');

        $asset = MediaAsset::query()->sole();
        $this->assertSame('video', $asset->kind);
        $this->assertSame('video/mp4', $asset->mime);
        Storage::disk('scratchpad')->assertExists($asset->path);
        $this->assertSame(3, Attachment::query()->where('role', 'motion')->sole()->position);
    }

    public function test_motion_slide_is_idempotent_per_slot_and_separate_per_language(): void
    {
        Storage::fake('scratchpad');

        $this->acting()->postJson('/api/v1/posts', [
            'human_id' => 'P-61',
            'number' => 61,
            'title' => 'Two languages',
        ])->assertCreated();

        $this->acting()->post('/api/v1/posts/P-61/videos', [
            'file' => $this->mp4('slide-1.mp4'),
            'platform' => 'instagram',
            'language' => 'en',
        ])->assertCreated();
        $this->acting()->post('/api/v1/posts/P-61/videos', [
            'file' => $this->mp4('slide-1.mp4'),
            'platform' => 'instagram',
            'language' => 'en',
        ])->assertOk();
        $this->acting()->post('/api/v1/posts/P-61/videos', [
            'file' => $this->mp4('slide-1.mp4'),
            'platform' => 'instagram',
            'language' => 'bn',
        ])->assertCreated();

        $this->assertSame(1, MediaAsset::query()->count());
        $this->assertSame(2, Attachment::query()->where('role', 'motion')->count());
    }

    public function test_motion_upload_rejects_non_mp4_oversize_and_unknown_slots(): void
    {
        Storage::fake('scratchpad');

        $this->acting()->postJson('/api/v1/posts', [
            'human_id' => 'P-62',
            'number' => 62,
            'title' => 'Validation',
        ])->assertCreated();

        $json = ['Accept' => 'application/json'];

        $this->acting()->withHeaders($json)->post('/api/v1/posts/P-62/videos', [
            'file' => UploadedFile::fake()->image('cover.jpg'),
            'platform' => 'instagram',
            'language' => 'en',
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->acting()->withHeaders($json)->post('/api/v1/posts/P-62/videos', [
            'file' => $this->mp4('huge.mp4', 25601),
            'platform' => 'instagram',
            'language' => 'en',
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->acting()->withHeaders($json)->post('/api/v1/posts/P-62/videos', [
            'file' => $this->mp4('slide.mp4'),
            'platform' => 'tiktok',
            'language' => 'en',
        ])->assertUnprocessable()->assertJsonValidationErrors('platform');

        $this->acting()->withHeaders($json)->post('/api/v1/posts/P-62/videos', [
            'file' => $this->mp4('slide.mp4'),
            'platform' => 'instagram',
            'language' => 'fr',
        ])->assertUnprocessable()->assertJsonValidationErrors('language');

        $this->assertSame(0, Attachment::query()->count());
    }

    public function test_reattaching_earlier_bytes_under_a_reused_name_makes_them_the_newest_copy(): void
    {
        Storage::fake('scratchpad');

        $this->acting()->postJson('/api/v1/posts', [
            'human_id' => 'P-65',
            'number' => 65,
            'title' => 'Revert to earlier bytes',
        ])->assertCreated();

        $slide = fn (int $kb) => [
            'file' => $this->mp4('slide-1.mp4', $kb),
            'platform' => 'instagram',
            'language' => 'en',
        ];

        $this->acting()->post('/api/v1/posts/P-65/videos', $slide(1))->assertCreated();
        $this->acting()->post('/api/v1/posts/P-65/videos', $slide(2))->assertCreated();
        $this->acting()->post('/api/v1/posts/P-65/videos', $slide(1))->assertCreated();

        $newest = Attachment::query()->where('role', 'motion')->latest('id')->firstOrFail();
        $firstAsset = MediaAsset::query()->orderBy('id')->firstOrFail();
        $this->assertSame($firstAsset->id, $newest->media_asset_id);
        $this->assertSame(3, Attachment::query()->where('role', 'motion')->count());
    }

    public function test_motion_upload_refuses_identical_bytes_under_a_different_name(): void
    {
        Storage::fake('scratchpad');

        $this->acting()->postJson('/api/v1/posts', [
            'human_id' => 'P-64',
            'number' => 64,
            'title' => 'Name clash',
        ])->assertCreated();

        $this->acting()->post('/api/v1/posts/P-64/videos', [
            'file' => $this->mp4('slide.mp4'),
            'filename' => 'slide-en.mp4',
            'platform' => 'instagram',
            'language' => 'en',
        ])->assertCreated();

        $this->acting()->withHeaders(['Accept' => 'application/json'])->post('/api/v1/posts/P-64/videos', [
            'file' => $this->mp4('slide.mp4'),
            'filename' => 'slide-bn.mp4',
            'platform' => 'instagram',
            'language' => 'bn',
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertSame(1, Attachment::query()->where('role', 'motion')->count());
    }

    public function test_motion_upload_accepts_a_slide_of_25_mb(): void
    {
        Storage::fake('scratchpad');

        $this->acting()->postJson('/api/v1/posts', [
            'human_id' => 'P-63',
            'number' => 63,
            'title' => 'Largest slide',
        ])->assertCreated();

        $this->acting()->post('/api/v1/posts/P-63/videos', [
            'file' => $this->mp4('largest.mp4', 25600),
            'platform' => 'instagram',
            'language' => 'bn',
        ])->assertCreated();
    }

    public function test_publish_dispatches_job_and_returns_queued_state(): void
    {
        Queue::fake();
        $this->configurePostsyncer();

        $this->acting()->postJson('/api/v1/posts', [
            'human_id' => 'P-1',
            'number' => 1,
            'title' => 'Queue probe',
            'status' => 'draft',
        ])->assertCreated();

        $this->acting()->postJson('/api/v1/posts/P-1/publish', [
            'when' => '2026-08-28T22:00:00+06:00',
            'platforms' => ['facebook'],
            'confirm_ask' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.human_id', 'P-1')
            ->assertJsonPath('data.publish_state', 'queued')
            ->assertJsonPath('data.publish_error', null);

        $post = Post::query()->where('human_id', 'P-1')->sole();

        Queue::assertPushed(PublishPostJob::class, function (PublishPostJob $job) use ($post) {
            return $job->post->is($post)
                && $job->options['when'] === '2026-08-28T22:00:00+06:00'
                && $job->options['platforms'] === ['facebook']
                && $job->options['confirm_ask'] === true;
        });
    }

    public function test_publish_on_a_scheduled_post_only_appends_when_platforms_are_explicit(): void
    {
        Queue::fake();
        $this->configurePostsyncer();

        $group = [
            'post_id' => '500',
            'status' => 'SCHEDULED',
            'scheduled_at' => '2026-10-10T08:00:00+06:00',
            'platforms' => ['facebook'],
            'language' => 'bangla',
        ];
        $post = Post::factory()->for($this->workspace)->create([
            'human_id' => 'P-144',
            'number' => 144,
            'status' => 'scheduled',
            'publish_state' => 'succeeded',
            'platforms' => ['facebook', 'linkedin'],
            'captions' => ['facebook' => 'FB', 'linkedin' => 'LI'],
            'postsyncer' => ['groups' => [$group]],
        ]);

        // No explicit platforms: legacy refusal is unchanged.
        $this->acting()->postJson('/api/v1/posts/P-144/publish', [
            'when' => '2026-10-10T09:00:00+06:00',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('publish');

        // Already-scheduled platform is refused and named.
        $this->acting()->postJson('/api/v1/posts/P-144/publish', [
            'when' => '2026-10-10T09:00:00+06:00',
            'platforms' => ['facebook'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('platforms');

        // Missing `when` is refused.
        $this->acting()->postJson('/api/v1/posts/P-144/publish', [
            'platforms' => ['linkedin'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('when');

        Queue::assertNothingPushed();
        $this->assertEquals([$group], $post->fresh()->postsyncer['groups']);
    }

    public function test_approve_uses_the_api_token_owner_and_returns_approved_state(): void
    {
        $post = Post::factory()->for($this->workspace)->create([
            'human_id' => 'P-APPROVE-API',
            'number' => 65,
            'status' => 'draft',
            'approval_state' => 'pending',
        ]);

        $this->acting()->postJson('/api/v1/posts/'.$post->human_id.'/approve')
            ->assertOk()
            ->assertJsonPath('data.human_id', 'P-APPROVE-API')
            ->assertJsonPath('data.approval_state', 'approved');

        $this->assertDatabaseHas('posts', [
            'id' => $post->id,
            'approval_state' => 'approved',
        ]);
    }

    public function test_publish_rejects_when_postsyncer_is_not_ready(): void
    {
        Queue::fake();

        $this->acting()->postJson('/api/v1/posts', [
            'human_id' => 'P-2',
            'number' => 2,
            'title' => 'Not ready',
        ])->assertCreated();

        $this->acting()->postJson('/api/v1/posts/P-2/publish', [
            'when' => '2026-08-28T22:00:00+06:00',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('publish');

        Queue::assertNothingPushed();
        $this->assertSame('idle', Post::query()->where('human_id', 'P-2')->value('publish_state'));
    }

    public function test_publish_rejects_when_already_queued(): void
    {
        Queue::fake();
        $this->configurePostsyncer();

        Post::factory()->for($this->workspace)->create([
            'human_id' => 'P-3',
            'number' => 3,
            'publish_state' => 'queued',
        ]);

        $this->acting()->postJson('/api/v1/posts/P-3/publish', [
            'when' => '2026-08-28T22:00:00+06:00',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('publish');

        Queue::assertNothingPushed();
    }

    public function test_patch_cannot_reset_a_checkpointed_publish(): void
    {
        $post = Post::factory()->for($this->workspace)->create([
            'human_id' => 'P-60',
            'number' => 60,
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

        $this->acting()->patchJson('/api/v1/posts/'.$post->human_id, [
            'publish_state' => 'idle',
            'publish_error' => null,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('publish_state');

        $this->assertSame('failed', $post->refresh()->publish_state);
    }

    public function test_reconcile_endpoint_checkpoints_a_verified_postsyncer_post(): void
    {
        PostsyncerConfig::write($this->workspace, [
            'publish_enabled' => true,
            'api_key' => 'test-api-key',
            'languages' => [
                'bangla' => [
                    'workspace_id' => '15211',
                    'platforms' => ['facebook' => ['account_id' => 100]],
                ],
            ],
            'post_types' => [
                'platforms' => ['facebook' => ['text' => 'on']],
                'overrides' => [],
            ],
        ]);

        $post = Post::factory()->for($this->workspace)->create([
            'human_id' => 'P-61',
            'number' => 61,
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Reconcile this caption'],
            'status' => 'ready',
        ]);

        Http::fake([
            'postsyncer.com/api/v1/posts' => Http::response(['message' => 'gateway timeout'], 500),
        ]);

        try {
            app(PublishPostAction::class)->handle($post, [
                'when' => '2099-09-03T09:00:00+06:00',
                'confirm_ask' => false,
            ]);
        } catch (PostsyncerException) {
            // The failed create is the state this endpoint repairs.
        }

        Http::fake([
            'postsyncer.com/api/v1/posts/99' => Http::response([
                'id' => 99,
                'workspace_id' => 15211,
                'content' => [[
                    'text' => 'Reconcile this caption',
                    'media' => [],
                ]],
                'platforms' => [['platform' => 'facebook', 'account_id' => 100, 'settings' => [
                    'post_type' => 'POST',
                    'caption' => 'Reconcile this caption',
                ]]],
                'status' => 'SCHEDULED',
                'scheduled_at' => '2099-09-03T09:00:00+06:00',
            ], 200),
        ]);

        $this->acting()->postJson('/api/v1/posts/P-61/reconcile', [
            'postsyncer_id' => '99',
        ])
            ->assertOk()
            ->assertJsonPath('data.publish_progress.completed_groups.0.post_id', '99');
    }

    public function test_reconcile_media_endpoint_checkpoints_verified_media_ids(): void
    {
        $post = Post::factory()->for($this->workspace)->create([
            'human_id' => 'P-MEDIA-API-RECONCILE',
            'number' => 62,
            'publish_state' => 'failed',
            'publish_progress' => [
                'version' => 1,
                'operation_id' => 'operation-media-api',
                'run_token' => 'run-media-api',
                'options' => ['when' => null, 'confirm_ask' => false],
                'plan_hash' => 'plan-media-api',
                'planned_groups' => [],
                'completed_groups' => [],
                'current' => [
                    'index' => 0,
                    'group_key' => 'group-media-api',
                    'phase' => 'uploading',
                    'idempotency_key' => 'idempotency-media-api',
                    'media_ids' => [],
                    'media_urls' => ['https://example.com/image.png'],
                ],
                'state' => 'uncertain',
            ],
        ]);

        $this->acting()->postJson('/api/v1/posts/'.$post->human_id.'/reconcile-media', [
            'media_ids' => [915],
        ])
            ->assertOk()
            ->assertJsonPath('data.publish_state', 'failed')
            ->assertJsonPath('data.publish_progress.current.phase', 'retryable')
            ->assertJsonPath('data.publish_progress.current.media_ids.0', '915');
    }

    public function test_reconcile_media_absent_endpoint_resets_verified_empty_upload(): void
    {
        PostsyncerConfig::write($this->workspace, [
            'publish_enabled' => true,
            'api_key' => 'test-api-key',
            'languages' => [
                'bangla' => [
                    'workspace_id' => '15211',
                    'platforms' => ['facebook' => ['account_id' => 100]],
                ],
            ],
            'post_types' => [
                'platforms' => ['facebook' => ['photo' => 'on']],
                'overrides' => [],
            ],
        ]);

        $post = Post::factory()->for($this->workspace)->create([
            'human_id' => 'P-MEDIA-ABSENT-API',
            'number' => 63,
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Caption'],
            'image_drive_urls' => ['https://drive.google.com/file/d/abc/view'],
        ]);

        Http::fake([
            'postsyncer.com/api/v1/media/upload/url' => Http::response([
                'message' => 'temporary outage',
            ], 503),
        ]);

        try {
            app(PublishPostAction::class)->handle($post, [
                'when' => '2099-09-03T09:00:00+06:00',
                'confirm_ask' => false,
            ]);
        } catch (PostsyncerException) {
            // The failed upload creates the uncertain checkpoint this endpoint repairs.
        }

        $post->refresh();
        $this->assertSame('uncertain', $post->publish_progress['state']);
        $this->assertSame('uploading', $post->publish_progress['current']['phase']);

        $this->acting()->postJson('/api/v1/posts/'.$post->human_id.'/reconcile-media-absent', [
            'confirmed_absent' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.publish_state', 'failed')
            ->assertJsonPath('data.publish_progress.state', 'failed')
            ->assertJsonPath('data.publish_progress.current', null)
            ->assertJsonPath(
                'data.publish_progress.media_upload_recovery.mode',
                'operator_confirmed_absent',
            );
    }

    public function test_reconcile_create_absent_endpoint_makes_verified_empty_create_retryable(): void
    {
        PostsyncerConfig::write($this->workspace, [
            'publish_enabled' => true,
            'api_key' => 'test-api-key',
            'languages' => [
                'bangla' => [
                    'workspace_id' => '15211',
                    'platforms' => ['facebook' => ['account_id' => 100]],
                ],
            ],
            'post_types' => [
                'platforms' => ['facebook' => ['photo' => 'on']],
                'overrides' => [],
            ],
        ]);

        $post = Post::factory()->for($this->workspace)->create([
            'human_id' => 'P-CREATE-ABSENT-API',
            'number' => 64,
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Caption'],
            'image_drive_urls' => ['https://drive.google.com/file/d/abc/view'],
        ]);

        Http::fake([
            'postsyncer.com/api/v1/media/upload/url' => Http::response([
                'media' => [['id' => 915]],
                'count_stored' => 1,
            ], 200),
            'postsyncer.com/api/v1/posts' => Http::response([
                'message' => 'temporary outage',
            ], 500),
        ]);

        app(PublishPostAction::class)->handle($post, [
            'when' => '2099-09-03T09:00:00+06:00',
            'confirm_ask' => false,
        ]);

        $post->refresh();
        $this->assertSame('uncertain', $post->publish_progress['state']);
        $this->assertSame('creating', $post->publish_progress['current']['phase']);

        $this->acting()->postJson('/api/v1/posts/'.$post->human_id.'/reconcile-create-absent', [
            'confirmed_absent' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.publish_state', 'failed')
            ->assertJsonPath('data.publish_progress.state', 'failed')
            ->assertJsonPath('data.publish_progress.current.phase', 'retryable')
            ->assertJsonPath('data.publish_progress.current.media_ids.0', '915')
            ->assertJsonPath(
                'data.publish_progress.create_recovery.mode',
                'operator_confirmed_absent',
            );
    }

    public function test_rebase_retry_to_publish_now_preserves_unfinished_media_ids(): void
    {
        $this->rebaseAfterCompletedGroupStatus('P-REBASE-NOW-API', 'PUBLISHED')
            ->assertOk()
            ->assertJsonPath('data.publish_state', 'failed')
            ->assertJsonPath('data.publish_progress.options.when', null)
            ->assertJsonPath('data.publish_progress.schedule_recovery.mode', 'past_schedule_to_publish_now')
            ->assertJsonPath('data.publish_progress.completed_groups.0.status', 'PUBLISHED')
            ->assertJsonPath('data.publish_progress.completed_groups.0.scheduled_at', null)
            ->assertJsonMissingPath('data.publish_progress.completed_groups.0.operator_confirmed')
            ->assertJsonPath('data.publish_progress.current.phase', 'retryable')
            ->assertJsonPath('data.publish_progress.current.media_ids.0', '9001');
    }

    public function test_rebase_retry_to_publish_now_accepts_a_remotely_failed_completed_group(): void
    {
        $this->rebaseAfterCompletedGroupStatus('P-REBASE-NOW-FAILED', 'FAILED')
            ->assertOk()
            ->assertJsonPath('data.publish_progress.schedule_recovery.mode', 'past_schedule_to_publish_now')
            ->assertJsonPath('data.publish_progress.completed_groups.0.post_id', '1001')
            ->assertJsonPath('data.publish_progress.completed_groups.0.status', 'FAILED')
            ->assertJsonPath('data.publish_progress.completed_groups.0.operator_confirmed', true)
            ->assertJsonPath('data.publish_progress.current.index', 1)
            ->assertJsonPath('data.publish_progress.current.phase', 'retryable');
    }

    public function test_rebase_retry_to_publish_now_rejects_a_still_scheduled_completed_group(): void
    {
        $this->rebaseAfterCompletedGroupStatus('P-REBASE-NOW-SCHED', 'SCHEDULED')
            ->assertStatus(422)
            ->assertJsonPath('errors.publish.0', 'Every completed group must be PUBLISHED or FAILED before a schedule can be rebased to publish-now.');
    }

    private function rebaseAfterCompletedGroupStatus(string $humanId, string $remoteStatus): TestResponse
    {
        PostsyncerConfig::write($this->workspace, [
            'publish_enabled' => true,
            'api_key' => 'test-api-key',
            'languages' => [
                'bangla' => [
                    'workspace_id' => '15211',
                    'platforms' => [
                        'twitter' => ['account_id' => 100],
                        'facebook' => ['account_id' => 101],
                    ],
                ],
            ],
            'post_types' => [
                'platforms' => [
                    'twitter' => ['text' => 'on'],
                    'facebook' => ['photo' => 'on'],
                ],
                'overrides' => [],
            ],
        ]);

        $post = Post::factory()->for($this->workspace)->create([
            'human_id' => $humanId,
            'number' => 66,
            'status' => 'ready',
            'approval_state' => 'approved',
            'language' => 'bn',
            'platforms' => ['twitter', 'facebook'],
            'captions' => [
                'twitter' => [
                    'caption' => 'A short thread',
                    'thread' => ['A follow-up'],
                    'images' => [],
                ],
                'facebook' => ['caption' => 'A photo post'],
            ],
            'image_drive_urls' => ['https://drive.google.com/file/d/example/view'],
        ]);

        $createdRemote = null;
        $createCount = 0;
        $getStatus = 'PUBLISHED';
        Http::fake(function ($request) use (&$createdRemote, &$createCount, &$getStatus) {
            $url = $request->url();

            if (str_ends_with($url, '/media/upload/url')) {
                return Http::response([
                    'media' => [['id' => 9001]],
                    'count_stored' => 1,
                ], 200);
            }

            if (str_ends_with($url, '/posts') && $request->method() === 'POST') {
                $createCount++;
                if ($createCount === 1) {
                    $body = $request->data();
                    $account = $body['accounts'][0];
                    $createdRemote = [
                        'id' => 1001,
                        'workspace_id' => '15211',
                        'status' => 'PUBLISHED',
                        'scheduled_at' => '2020-01-01 00:00',
                        'content' => $body['content'],
                        'platforms' => [[
                            'platform' => 'twitter',
                            'account_id' => $account['id'],
                            'settings' => $account['settings'],
                        ]],
                    ];

                    return Http::response($createdRemote, 200);
                }

                return Http::response(['message' => 'temporary outage'], 500);
            }

            if (str_ends_with($url, '/posts/1001') && $request->method() === 'GET') {
                return Http::response(array_merge($createdRemote, ['status' => $getStatus]), 200);
            }

            return Http::response(['message' => 'unexpected request'], 404);
        });

        $action = app(PublishPostAction::class);
        $action->handle($post, [
            'when' => '2020-01-01T00:00:00+06:00',
            'confirm_ask' => false,
        ]);

        $post->refresh();
        $this->assertSame('uncertain', $post->publish_progress['state']);
        $this->assertSame(['9001'], array_map('strval', $post->publish_progress['current']['media_ids']));

        $action->reconcileCreateAbsent($post);

        // The remote status changes only after the original schedule run, as on a real past slot.
        $getStatus = $remoteStatus;

        return $this->acting()->postJson('/api/v1/posts/'.$post->human_id.'/rebase-retry-publish-now');
    }

    public function test_abandon_retry_endpoint_drops_a_retryable_checkpoint_and_keeps_completed_groups(): void
    {
        $post = $this->failedPostWithCheckpoint('P-ABANDON-RETRY', 'retryable', 'failed');

        $this->acting()->postJson('/api/v1/posts/'.$post->human_id.'/abandon-retry', [
            'confirmed_absent' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.publish_state', 'failed')
            ->assertJsonPath('data.publish_progress.current', null)
            ->assertJsonPath('data.publish_progress.abandoned.0.group_key', 'group-retry')
            ->assertJsonPath('data.publish_progress.abandoned.0.media_ids.0', '915')
            ->assertJsonPath('data.publish_progress.completed_groups.0.post_id', '135500');
    }

    public function test_abandon_retry_endpoint_refuses_an_uncertain_checkpoint(): void
    {
        $post = $this->failedPostWithCheckpoint('P-ABANDON-UNCERTAIN', 'creating', 'uncertain');

        $this->acting()->postJson('/api/v1/posts/'.$post->human_id.'/abandon-retry', [
            'confirmed_absent' => true,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('publish');

        $this->assertSame('creating', $post->fresh()->publish_progress['current']['phase']);
    }

    private function failedPostWithCheckpoint(string $humanId, string $phase, string $state): Post
    {
        $post = Post::factory()->for($this->workspace)->create([
            'human_id' => $humanId,
            'number' => random_int(1000, 99999),
            'status' => 'ready',
            'language' => 'en',
            'platforms' => ['facebook', 'youtube'],
        ]);

        $post->forceFill([
            'publish_state' => 'failed',
            'publish_progress' => [
                'version' => 1,
                'operation_id' => 'operation-'.$humanId,
                'run_token' => 'run-'.$humanId,
                'options' => ['when' => '2099-01-01T09:00:00+06:00', 'confirm_ask' => false],
                'plan_hash' => 'plan-'.$humanId,
                'planned_groups' => [
                    ['index' => 0, 'group_key' => 'group-done'],
                    ['index' => 1, 'group_key' => 'group-retry'],
                ],
                'completed_groups' => [[
                    'index' => 0,
                    'group_key' => 'group-done',
                    'post_id' => '135500',
                    'status' => 'PUBLISHED',
                    'platforms' => ['facebook'],
                ]],
                'current' => [
                    'index' => 1,
                    'group_key' => 'group-retry',
                    'phase' => $phase,
                    'idempotency_key' => 'key-'.$humanId,
                    'media_ids' => [915],
                    'media_urls' => ['https://example.com/media.png'],
                ],
                'state' => $state,
            ],
        ])->save();

        return $post;
    }

    public function test_repair_account_mapping_endpoint_rebases_a_partial_publish(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/accounts' => Http::response([
                'data' => [[
                    'id' => 7541,
                    'workspace_id' => 853,
                    'platform' => 'instagram',
                    'username' => 'harundotdev',
                    'has_expired' => false,
                ]],
            ], 200),
        ]);

        PostsyncerConfig::write($this->workspace, [
            'publish_enabled' => true,
            'api_key' => 'test-api-key',
            'languages' => [
                'bangla' => [
                    'workspace_id' => '15211',
                    'platforms' => ['facebook' => ['account_id' => 100]],
                ],
                'english' => [
                    'workspace_id' => '853',
                    'platforms' => [
                        'facebook' => ['account_id' => 200],
                        'instagram' => ['account_id' => 7364],
                    ],
                ],
            ],
            'post_types' => [
                'platforms' => [
                    'facebook' => ['text' => 'on'],
                    'instagram' => ['text' => 'on'],
                ],
                'overrides' => [],
            ],
        ]);

        $post = Post::factory()->for($this->workspace)->create([
            'human_id' => 'P-API-ACCOUNT-REPAIR',
            'number' => 63,
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook', 'instagram'],
            'captions' => [
                'Bangla' => ['facebook' => ['caption' => 'Bangla caption']],
                'English' => [
                    'facebook' => ['caption' => 'English Facebook caption'],
                    'instagram' => ['caption' => 'English Instagram caption'],
                ],
            ],
        ]);
        $options = [
            'when' => '2026-09-04T09:23:00+06:00',
            'platforms' => ['facebook', 'instagram'],
            'confirm_ask' => false,
        ];
        $action = app(PublishPostAction::class);
        $plan = $action->freezePlan($post, PostsyncerConfig::fromWorkspace($this->workspace), $options);
        $currentKey = $plan['groups'][1]['group_key'];

        $post->forceFill([
            'publish_state' => 'failed',
            'publish_progress' => [
                'version' => 1,
                'operation_id' => 'operation-api-repair',
                'run_token' => 'run-api-repair',
                'options' => $plan['options'],
                'plan_hash' => $plan['hash'],
                'planned_groups' => $plan['groups'],
                'completed_groups' => [[
                    'index' => 0,
                    'group_key' => $plan['groups'][0]['group_key'],
                    'post_id' => '135500',
                    'status' => 'SCHEDULED',
                    'scheduled_at' => '2026-09-04 09:23',
                    'platforms' => ['facebook'],
                    'language' => 'bangla',
                ]],
                'current' => [
                    'index' => 1,
                    'group_key' => $currentKey,
                    'phase' => 'retryable',
                    'idempotency_key' => 'old-api-repair-key',
                    'media_ids' => [],
                    'media_urls' => [],
                ],
                'state' => 'failed',
            ],
        ])->save();

        $this->acting()->postJson('/api/v1/posts/'.$post->human_id.'/repair-account-mapping', [
            'language' => 'english',
            'platform' => 'instagram',
            'from_account_id' => 7364,
            'to_account_id' => 7541,
        ])
            ->assertOk()
            ->assertJsonPath('data.publish_state', 'failed')
            ->assertJsonPath('data.publish_progress.account_mapping_repair.to_account_id', '7541')
            ->assertJsonPath('data.publish_progress.current.phase', 'retryable');

        $this->workspace->refresh();
        $this->assertSame(
            '7541',
            (string) PostsyncerConfig::fromWorkspace($this->workspace)
                ->language('english')['platforms']['instagram']['account_id'],
        );
        Http::assertSentCount(1);
    }

    private function configurePostsyncer(): void
    {
        PostsyncerConfig::write($this->workspace, [
            'publish_enabled' => true,
            'api_key' => 'test-api-key',
            'languages' => [
                'bangla' => ['workspace_id' => '15211', 'platforms' => []],
                'english' => ['workspace_id' => '853', 'platforms' => []],
            ],
        ]);
    }
}
