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
