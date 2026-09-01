<?php

namespace Tests\Unit\Actions\Posts;

use App\Actions\Posts\AttachPostImageAction;
use App\Data\Posts\AttachPostImageData;
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

class AttachPostImageActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_the_file_and_attaches_it_to_the_post(): void
    {
        Storage::fake('scratchpad');

        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();
        $post = Post::factory()->for($workspace)->create();
        $file = UploadedFile::fake()->image('cover.png', 200, 100);

        $updated = (new AttachPostImageAction)->handle(
            $post,
            $user,
            new AttachPostImageData(file: $file),
        );

        $this->assertSame($post->id, $updated->id);

        $mediaAsset = MediaAsset::sole();
        $this->assertSame($workspace->id, $mediaAsset->workspace_id);
        $this->assertSame('image', $mediaAsset->kind);
        $this->assertSame('scratchpad', $mediaAsset->disk);
        $this->assertSame($user->id, $mediaAsset->uploaded_by_user_id);
        $this->assertSame('cover.png', $mediaAsset->original_filename);
        $this->assertSame(200, $mediaAsset->width);
        $this->assertSame(100, $mediaAsset->height);
        Storage::disk('scratchpad')->assertExists($mediaAsset->path);

        $attachment = Attachment::sole();
        $this->assertSame($post->id, $attachment->attachable_id);
        $this->assertSame($post->getMorphClass(), $attachment->attachable_type);
        $this->assertSame($mediaAsset->id, $attachment->media_asset_id);
        $this->assertSame('image', $attachment->role);
        $this->assertSame(0, $attachment->position);
    }

    public function test_a_duplicate_upload_on_the_same_post_reuses_the_attachment(): void
    {
        Storage::fake('scratchpad');

        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();
        $post = Post::factory()->for($workspace)->create();
        $action = new AttachPostImageAction;

        $first = $action->handle(
            $post,
            $user,
            new AttachPostImageData(file: UploadedFile::fake()->image('cover.png', 200, 100)),
        );
        $second = $action->handle(
            $post->fresh(),
            $user,
            new AttachPostImageData(file: UploadedFile::fake()->image('cover.png', 200, 100)),
        );

        $this->assertSame($first->attachments()->sole()->id, $second->attachments()->sole()->id);
        $this->assertSame(1, MediaAsset::count());
        $this->assertSame(1, Attachment::count());
    }

    public function test_it_rejects_an_upload_while_a_postsyncer_publish_is_in_progress(): void
    {
        Storage::fake('scratchpad');

        $post = Post::factory()->create(['publish_state' => 'queued']);

        $this->expectException(ValidationException::class);

        (new AttachPostImageAction)->handle(
            $post,
            null,
            new AttachPostImageData(file: UploadedFile::fake()->image('cover.png')),
        );
    }
}
