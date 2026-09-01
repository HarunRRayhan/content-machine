<?php

namespace Tests\Unit\Actions\Posts;

use App\Actions\Posts\AttachPostDocumentAction;
use App\Data\Posts\AttachPostDocumentData;
use App\Models\Attachment;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AttachPostDocumentActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_a_pdf_and_attaches_it_as_a_linkedin_document(): void
    {
        Storage::fake('scratchpad');

        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();
        $post = Post::factory()->for($workspace)->create();
        $file = UploadedFile::fake()->create('p-50-linkedin-carousel.pdf', 80, 'application/pdf');

        $updated = (new AttachPostDocumentAction)->handle(
            $post,
            $user,
            new AttachPostDocumentData(file: $file),
        );

        $this->assertSame($post->id, $updated->id);

        $mediaAsset = MediaAsset::sole();
        $this->assertSame($workspace->id, $mediaAsset->workspace_id);
        $this->assertSame('document', $mediaAsset->kind);
        $this->assertSame('scratchpad', $mediaAsset->disk);
        $this->assertSame($user->id, $mediaAsset->uploaded_by_user_id);
        $this->assertSame('p-50-linkedin-carousel.pdf', $mediaAsset->original_filename);
        Storage::disk('scratchpad')->assertExists($mediaAsset->path);

        $attachment = Attachment::sole();
        $this->assertSame($post->id, $attachment->attachable_id);
        $this->assertSame($post->getMorphClass(), $attachment->attachable_type);
        $this->assertSame($mediaAsset->id, $attachment->media_asset_id);
        $this->assertSame('document', $attachment->role);
        $this->assertSame('linkedin', $attachment->platform);
        $this->assertSame(0, $attachment->position);
    }

    public function test_a_duplicate_upload_on_the_same_post_reuses_the_attachment(): void
    {
        Storage::fake('scratchpad');

        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();
        $post = Post::factory()->for($workspace)->create();
        $action = new AttachPostDocumentAction;

        $first = $action->handle(
            $post,
            $user,
            new AttachPostDocumentData(file: UploadedFile::fake()->create('deck.pdf', 40, 'application/pdf')),
        );
        $second = $action->handle(
            $post->fresh(),
            $user,
            new AttachPostDocumentData(file: UploadedFile::fake()->create('deck.pdf', 40, 'application/pdf')),
        );

        $this->assertSame($first->attachments()->sole()->id, $second->attachments()->sole()->id);
        $this->assertSame(1, MediaAsset::count());
        $this->assertSame(1, Attachment::count());
    }

    public function test_it_rejects_an_upload_while_a_postsyncer_publish_is_in_progress(): void
    {
        Storage::fake('scratchpad');

        $post = Post::factory()->create(['publish_state' => 'running']);

        $this->expectException(ValidationException::class);

        (new AttachPostDocumentAction)->handle(
            $post,
            null,
            new AttachPostDocumentData(file: UploadedFile::fake()->create('deck.pdf', 40, 'application/pdf')),
        );
    }
}
