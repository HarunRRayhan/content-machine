<?php

namespace Tests\Unit\Actions\Posts;

use App\Actions\Posts\AttachPostDocumentAction;
use App\Data\Posts\AttachPostDocumentData;
use App\Models\Attachment;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\Transcription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AttachPostDocumentActionTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->beforeApplicationDestroyed(static function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

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

    public function test_a_new_upload_removes_previous_document_generations(): void
    {
        Storage::fake('scratchpad');

        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();
        $post = Post::factory()->for($workspace)->create();
        $action = new AttachPostDocumentAction;

        $action->handle(
            $post,
            $user,
            new AttachPostDocumentData(file: UploadedFile::fake()->createWithContent('old.pdf', 'old pdf bytes')),
        );
        $oldAsset = MediaAsset::sole();

        $action->handle(
            $post->fresh(),
            $user,
            new AttachPostDocumentData(file: UploadedFile::fake()->createWithContent('new.pdf', 'new pdf bytes')),
        );

        $this->assertSame(1, MediaAsset::count());
        $this->assertSame(1, Attachment::count());
        $this->assertSame('new.pdf', MediaAsset::sole()->original_filename);
        $this->assertDatabaseMissing('media_assets', ['id' => $oldAsset->id]);
    }

    public function test_an_outer_transaction_rollback_keeps_previous_document_reachable(): void
    {
        Storage::fake('scratchpad');

        $workspace = Workspace::factory()->create();
        $post = Post::factory()->for($workspace)->create();
        $action = new AttachPostDocumentAction;
        $action->handle(
            $post,
            null,
            new AttachPostDocumentData(file: UploadedFile::fake()->createWithContent('old.pdf', 'old pdf bytes')),
        );

        $oldAsset = MediaAsset::sole();
        $oldPath = $oldAsset->path;

        DB::beginTransaction();

        try {
            $action->handle(
                $post->fresh(),
                null,
                new AttachPostDocumentData(file: UploadedFile::fake()->createWithContent('new.pdf', 'new pdf bytes')),
            );
        } finally {
            DB::rollBack();
        }

        $this->assertDatabaseHas('media_assets', ['id' => $oldAsset->id]);
        $this->assertDatabaseHas('attachments', [
            'attachable_type' => $post->getMorphClass(),
            'attachable_id' => $post->id,
            'media_asset_id' => $oldAsset->id,
            'role' => 'document',
        ]);
        Storage::disk('scratchpad')->assertExists($oldPath);
    }

    public function test_a_committed_replacement_deletes_the_unreferenced_old_file(): void
    {
        Storage::fake('scratchpad');

        $workspace = Workspace::factory()->create();
        $post = Post::factory()->for($workspace)->create();
        $action = new AttachPostDocumentAction;

        $action->handle(
            $post,
            null,
            new AttachPostDocumentData(file: UploadedFile::fake()->createWithContent('old.pdf', 'old pdf bytes')),
        );
        $oldAsset = MediaAsset::sole();
        $oldPath = $oldAsset->path;

        $action->handle(
            $post->fresh(),
            null,
            new AttachPostDocumentData(file: UploadedFile::fake()->createWithContent('new.pdf', 'new pdf bytes')),
        );

        $this->assertDatabaseMissing('media_assets', ['id' => $oldAsset->id]);
        $this->assertDatabaseMissing('attachments', ['media_asset_id' => $oldAsset->id]);
        Storage::disk('scratchpad')->assertMissing($oldPath);
    }

    public function test_an_outer_commit_waits_to_delete_the_previous_file(): void
    {
        Storage::fake('scratchpad');

        $workspace = Workspace::factory()->create();
        $post = Post::factory()->for($workspace)->create();
        $action = new AttachPostDocumentAction;
        $action->handle(
            $post,
            null,
            new AttachPostDocumentData(file: UploadedFile::fake()->createWithContent('old.pdf', 'old pdf bytes')),
        );

        $oldAsset = MediaAsset::sole();
        $oldPath = $oldAsset->path;

        DB::beginTransaction();
        $action->handle(
            $post->fresh(),
            null,
            new AttachPostDocumentData(file: UploadedFile::fake()->createWithContent('new.pdf', 'new pdf bytes')),
        );

        Storage::disk('scratchpad')->assertExists($oldPath);
        DB::commit();

        $this->assertDatabaseMissing('media_assets', ['id' => $oldAsset->id]);
        Storage::disk('scratchpad')->assertMissing($oldPath);
    }

    public function test_a_replacement_keeps_an_old_file_that_still_has_an_attachment(): void
    {
        Storage::fake('scratchpad');

        $workspace = Workspace::factory()->create();
        $post = Post::factory()->for($workspace)->create();
        $otherPost = Post::factory()->for($workspace)->create();
        $action = new AttachPostDocumentAction;

        $action->handle(
            $post,
            null,
            new AttachPostDocumentData(file: UploadedFile::fake()->createWithContent('shared.pdf', 'shared pdf bytes')),
        );
        $oldAsset = MediaAsset::sole();
        $oldPath = $oldAsset->path;
        $otherAttachment = Attachment::factory()->for($otherPost, 'attachable')->for($oldAsset)->create([
            'role' => 'document',
            'platform' => 'linkedin',
        ]);

        $action->handle(
            $post->fresh(),
            null,
            new AttachPostDocumentData(file: UploadedFile::fake()->createWithContent('new.pdf', 'new pdf bytes')),
        );

        $this->assertDatabaseHas('media_assets', ['id' => $oldAsset->id]);
        $this->assertDatabaseHas('attachments', ['id' => $otherAttachment->id]);
        Storage::disk('scratchpad')->assertExists($oldPath);
    }

    public function test_a_replacement_keeps_an_old_asset_that_still_has_a_transcription(): void
    {
        Storage::fake('scratchpad');

        $workspace = Workspace::factory()->create();
        $post = Post::factory()->for($workspace)->create();
        $action = new AttachPostDocumentAction;

        $action->handle(
            $post,
            null,
            new AttachPostDocumentData(file: UploadedFile::fake()->createWithContent('transcribed.pdf', 'pdf bytes')),
        );
        $oldAsset = MediaAsset::sole();
        $oldPath = $oldAsset->path;
        $transcription = Transcription::factory()->for($oldAsset, 'mediaAsset')->create();

        $action->handle(
            $post->fresh(),
            null,
            new AttachPostDocumentData(file: UploadedFile::fake()->createWithContent('new.pdf', 'new pdf bytes')),
        );

        $this->assertDatabaseHas('media_assets', ['id' => $oldAsset->id]);
        $this->assertDatabaseHas('transcriptions', ['id' => $transcription->id]);
        Storage::disk('scratchpad')->assertExists($oldPath);
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

    public function test_it_rejects_an_upload_when_the_postsyncer_outcome_is_uncertain(): void
    {
        Storage::fake('scratchpad');

        $post = Post::factory()->create([
            'publish_state' => 'failed',
            'publish_progress' => [
                'state' => 'uncertain',
                'current' => ['index' => 0],
            ],
        ]);

        $this->expectException(ValidationException::class);

        (new AttachPostDocumentAction)->handle(
            $post,
            null,
            new AttachPostDocumentData(
                file: UploadedFile::fake()->create('deck.pdf', 40, 'application/pdf'),
            ),
        );
    }
}
