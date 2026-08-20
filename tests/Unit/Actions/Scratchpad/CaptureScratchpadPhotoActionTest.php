<?php

namespace Tests\Unit\Actions\Scratchpad;

use App\Actions\Scratchpad\CaptureScratchpadPhotoAction;
use App\Data\Scratchpad\CaptureScratchpadPhotoData;
use App\Models\Attachment;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CaptureScratchpadPhotoActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_the_file_and_creates_a_media_asset_attachment_and_entry()
    {
        Storage::fake('scratchpad');

        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('photo.jpg', 200, 100);

        $entry = (new CaptureScratchpadPhotoAction)->handle(
            $workspace,
            $user,
            new CaptureScratchpadPhotoData(file: $file, caption: 'A nice view'),
        );

        $this->assertSame($workspace->id, $entry->workspace_id);
        $this->assertSame('photo', $entry->kind);
        $this->assertSame('web', $entry->source);
        $this->assertSame('new', $entry->status);
        $this->assertSame('A nice view', $entry->body);
        $this->assertNotNull($entry->public_id);

        $mediaAsset = MediaAsset::sole();
        $this->assertSame($workspace->id, $mediaAsset->workspace_id);
        $this->assertSame('image', $mediaAsset->kind);
        $this->assertSame('scratchpad', $mediaAsset->disk);
        $this->assertSame($user->id, $mediaAsset->uploaded_by_user_id);
        $this->assertSame(200, $mediaAsset->width);
        $this->assertSame(100, $mediaAsset->height);
        $this->assertNotNull($mediaAsset->checksum_sha256);
        Storage::disk('scratchpad')->assertExists($mediaAsset->path);

        $attachment = Attachment::sole();
        $this->assertSame($entry->id, $attachment->attachable_id);
        $this->assertSame($entry->getMorphClass(), $attachment->attachable_type);
        $this->assertSame($mediaAsset->id, $attachment->media_asset_id);
        $this->assertSame('image', $attachment->role);
        $this->assertSame(0, $attachment->position);
    }

    public function test_a_duplicate_upload_reuses_the_existing_media_asset()
    {
        Storage::fake('scratchpad');

        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();

        $action = new CaptureScratchpadPhotoAction;

        $first = $action->handle(
            $workspace,
            $user,
            new CaptureScratchpadPhotoData(file: UploadedFile::fake()->image('photo.jpg', 200, 100), caption: null),
        );

        $second = $action->handle(
            $workspace,
            $user,
            new CaptureScratchpadPhotoData(file: UploadedFile::fake()->image('photo.jpg', 200, 100), caption: 'Again'),
        );

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(1, MediaAsset::count());
        $this->assertSame(2, Attachment::count());

        $mediaAsset = MediaAsset::sole();
        $this->assertSame($mediaAsset->id, Attachment::where('attachable_id', $first->id)->sole()->media_asset_id);
        $this->assertSame($mediaAsset->id, Attachment::where('attachable_id', $second->id)->sole()->media_asset_id);
    }

    public function test_it_records_a_null_to_new_status_transition()
    {
        Storage::fake('scratchpad');

        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();
        $this->actingAs($user);

        $entry = (new CaptureScratchpadPhotoAction)->handle(
            $workspace,
            $user,
            new CaptureScratchpadPhotoData(file: UploadedFile::fake()->image('photo.jpg'), caption: null),
        );

        $this->assertDatabaseHas('status_transitions', [
            'subject_type' => $entry->getMorphClass(),
            'subject_id' => $entry->id,
            'from' => null,
            'to' => 'new',
            'actor_type' => 'user',
            'actor_id' => $user->id,
        ]);
    }
}
