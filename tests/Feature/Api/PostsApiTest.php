<?php

namespace Tests\Feature\Api;

use App\Actions\ApiTokens\CreateWorkspaceApiTokenAction;
use App\Data\ApiTokens\CreateWorkspaceApiTokenData;
use App\Jobs\PublishSocialreinsJob;
use App\Models\Attachment;
use App\Models\Idea;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\SocialreinsGroup;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Socialreins\SocialreinsConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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

    public function test_a_new_post_gets_number_equal_to_its_id_and_a_p_label(): void
    {
        $response = $this->acting()->postJson('/api/v1/posts', ['title' => 'Generated post'])
            ->assertCreated();

        $id = $response->json('data.id');
        $response->assertJsonPath('data.number', $id)
            ->assertJsonPath('data.human_id', "P-{$id}");

        $second = $this->acting()->postJson('/api/v1/posts', ['title' => 'Another'])->assertCreated();
        $this->assertSame($second->json('data.id'), $second->json('data.number'));
        $this->assertGreaterThan($id, $second->json('data.id'));
    }

    public function test_an_explicit_import_keeps_its_human_id_and_number_while_id_differs(): void
    {
        $response = $this->acting()->postJson('/api/v1/posts', [
            'human_id' => 'P-290',
            'number' => 290,
            'title' => 'Imported post',
        ])->assertCreated()
            ->assertJsonPath('data.human_id', 'P-290')
            ->assertJsonPath('data.number', 290);

        $this->assertNotSame(290, $response->json('data.id'));

        // A generated post later never collides with the imported number.
        $generated = $this->acting()->postJson('/api/v1/posts', ['title' => 'Generated'])->assertCreated();
        $this->assertNotSame(290, $generated->json('data.number'));
    }

    private function createdId(array $payload = []): int
    {
        return $this->acting()->postJson('/api/v1/posts', $payload + ['title' => 'Probe'])->assertCreated()->json('data.id');
    }

    public function test_new_posts_never_reuse_an_imported_p_label_ahead_of_the_sequence(): void
    {
        $probe = $this->createdId();
        $taken = $probe + 5;
        $this->createdId(['human_id' => "P-{$taken}", 'number' => $taken]);

        $numbers = [];
        for ($i = 0; $i < 8; $i++) {
            $generated = $this->acting()->postJson('/api/v1/posts', ['title' => 'Generated'])->assertCreated();
            $this->assertSame($generated->json('data.id'), $generated->json('data.number'));
            $numbers[] = $generated->json('data.number');
        }

        $this->assertNotContains($taken, $numbers);
    }

    public function test_an_oversize_import_number_is_rejected(): void
    {
        $this->acting()->postJson('/api/v1/posts', ['title' => 'x', 'human_id' => 'P-1000000', 'number' => 1000000])
            ->assertUnprocessable()->assertJsonValidationErrors('number');
        $this->acting()->postJson('/api/v1/posts', ['title' => 'x', 'human_id' => 'P-99999999999999999999'])
            ->assertUnprocessable()->assertJsonValidationErrors('human_id');
    }

    public function test_an_import_far_above_the_sequence_is_rejected_without_moving_it(): void
    {
        $probe = $this->createdId();
        $far = $probe + 5000;

        $this->acting()->postJson('/api/v1/posts', ['title' => 'x', 'human_id' => "P-{$far}", 'number' => $far])
            ->assertUnprocessable()->assertJsonValidationErrors('number');

        $this->assertSame($probe + 1, $this->createdId());
    }

    public function test_imports_below_the_sequence_or_with_legacy_prefixes_leave_it_unchanged(): void
    {
        $probe = $this->createdId();
        $this->createdId(['human_id' => 'P-3', 'number' => 3]);
        $this->createdId(['human_id' => 'BP-'.($probe + 500), 'number' => $probe + 500]);

        $this->assertSame($probe + 3, $this->createdId());
    }

    public function test_a_post_resolves_by_p_label_and_bare_number_alike(): void
    {
        $new = $this->acting()->postJson('/api/v1/posts', ['title' => 'New one'])->assertCreated();
        $n = $new->json('data.number');

        foreach (["P-{$n}", "p-{$n}", (string) $n] as $key) {
            $this->acting()->getJson("/api/v1/posts/{$key}")
                ->assertOk()
                ->assertJsonPath('data.id', $new->json('data.id'));
        }

        $this->acting()->patchJson("/api/v1/posts/{$n}", ['title' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Renamed');
    }

    public function test_an_old_post_with_id_different_from_number_resolves_by_number_only(): void
    {
        $old = Post::factory()->create([
            'workspace_id' => $this->workspace->id,
            'number' => 290,
            'human_id' => 'P-290',
        ]);
        $this->assertNotSame(290, $old->id);

        $this->acting()->getJson('/api/v1/posts/290')->assertOk()->assertJsonPath('data.id', $old->id);
        $this->acting()->getJson('/api/v1/posts/P-290')->assertOk()->assertJsonPath('data.id', $old->id);
        // The database id is never an address.
        $this->acting()->getJson("/api/v1/posts/{$old->id}")->assertNotFound();
    }

    public function test_show_returns_the_socialreins_posts_of_a_post(): void
    {
        $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'number' => 324, 'human_id' => 'P-324']);

        $this->acting()->getJson('/api/v1/posts/P-324')->assertOk()->assertJsonPath('data.socialreins', []);

        $target = fn (string $status, ?string $error = null): array => [
            'status' => $status, 'remote_id' => null, 'remote_url' => null, 'parts' => 0, 'error' => $error, 'at' => 1, 'unknown' => false,
        ];
        SocialreinsGroup::query()->create([
            'workspace_id' => $this->workspace->id, 'subject_type' => 'post', 'subject_id' => $post->id,
            'language' => 'english', 'sequence' => 1, 'idempotency_key' => 'k', 'state' => 'created',
            'request' => json_encode(['scheduled_at' => '2026-11-12T05:27:00Z']),
            'group_id' => 'aaaaaaaa-0000-4000-8000-000000000001',
            'results' => [
                'p1' => ['platform' => 'facebook', 'status' => 'scheduled', 'error' => null, 'at' => 1, 'targets' => ['t1' => $target('pending')]],
                'p2' => ['platform' => 'instagram', 'status' => 'failed', 'error' => null, 'at' => 1, 'targets' => ['t2' => $target('failed', 'media rejected')]],
            ],
        ]);

        $this->acting()->getJson('/api/v1/posts/P-324')
            ->assertOk()
            ->assertJsonPath('data.socialreins', [
                [
                    'source' => 'socialreins', 'status' => 'scheduled', 'scheduled_at' => '2026-11-12T05:27:00Z',
                    'platforms' => ['facebook'], 'language' => 'english', 'post_id' => 'p1', 'detail' => null,
                    'targets' => [['status' => 'pending', 'remote_url' => null, 'error' => null]],
                ],
                [
                    'source' => 'socialreins', 'status' => 'failed', 'scheduled_at' => '2026-11-12T05:27:00Z',
                    'platforms' => ['instagram'], 'language' => 'english', 'post_id' => 'p2', 'detail' => 'Publishing failed. Inspect the dashboard for details.',
                    'targets' => [['status' => 'failed', 'remote_url' => null, 'error' => 'Publishing failed. Inspect the dashboard for details.']],
                ],
            ]);

        // A slim list skips it (one query per post); include=socialreins opts in.
        $this->acting()->getJson('/api/v1/posts')->assertOk()->assertJsonMissingPath('data.0.socialreins');
        $this->acting()->getJson('/api/v1/posts?include=socialreins')->assertOk()->assertJsonPath('data.0.socialreins.1.status', 'failed');
    }

    public function test_an_unknown_post_number_is_not_found(): void
    {
        $this->acting()->getJson('/api/v1/posts/999999')->assertNotFound();
        $this->acting()->getJson('/api/v1/posts/P-999999')->assertNotFound();
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
        $this->configureSocialreins($this->workspace);

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

        Queue::assertPushed(PublishSocialreinsJob::class, function (PublishSocialreinsJob $job) use ($post) {
            return $job->subject->is($post)
                && $job->options['when'] === '2026-08-28T22:00:00+06:00'
                && $job->options['platforms'] === ['facebook']
                && $job->options['confirm_ask'] === true;
        });
    }

    public function test_publish_refuses_a_post_published_through_postsyncer(): void
    {
        Queue::fake();
        $this->configureSocialreins($this->workspace);

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

        $this->acting()->postJson('/api/v1/posts/P-144/publish', [
            'when' => '2026-10-10T09:00:00+06:00',
            'platforms' => ['linkedin'],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.publish.0', SocialreinsConfig::POSTSYNCER_HISTORY_MESSAGE);

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

    public function test_publish_rejects_when_socialreins_is_not_ready(): void
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
        $this->configureSocialreins($this->workspace);

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
}
