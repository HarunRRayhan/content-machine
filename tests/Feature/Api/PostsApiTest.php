<?php

namespace Tests\Feature\Api;

use App\Actions\ApiTokens\CreateWorkspaceApiTokenAction;
use App\Data\ApiTokens\CreateWorkspaceApiTokenData;
use App\Models\Attachment;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
}
