<?php

namespace Tests\Unit\Actions\Scratchpad;

use App\Actions\Scratchpad\CaptureScratchpadVoiceAction;
use App\Data\Scratchpad\CaptureScratchpadVoiceData;
use App\Models\Attachment;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CaptureScratchpadVoiceActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_the_file_and_creates_a_media_asset_attachment_and_entry()
    {
        Storage::fake('scratchpad');

        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();
        // '.weba', not '.webm': Illuminate\Http\Testing\File freezes its
        // getClientMimeType() from the filename EXTENSION at construction
        // time (before the 3rd create() argument is applied), and Symfony's
        // mime map has '.webm' => video/webm, '.weba' => audio/webm. A real
        // browser upload doesn't go through this fake path, so it isn't
        // affected; see tests/Feature/Scratchpad/ScratchpadControllerTest's
        // real-UploadedFile-based tests for proof against genuine bytes.
        $file = UploadedFile::fake()->create('note.weba', 50, 'audio/webm');

        $entry = (new CaptureScratchpadVoiceAction)->handle(
            $workspace,
            $user,
            new CaptureScratchpadVoiceData(file: $file, language: 'bn'),
        );

        $this->assertSame($workspace->id, $entry->workspace_id);
        $this->assertSame('voice', $entry->kind);
        $this->assertSame('web', $entry->source);
        $this->assertSame('new', $entry->status);
        $this->assertSame('bn', $entry->language);
        $this->assertNull($entry->body);
        $this->assertNotNull($entry->public_id);

        $mediaAsset = MediaAsset::sole();
        $this->assertSame($workspace->id, $mediaAsset->workspace_id);
        $this->assertSame('audio', $mediaAsset->kind);
        $this->assertSame('scratchpad', $mediaAsset->disk);
        $this->assertSame('audio/webm', $mediaAsset->mime);
        $this->assertSame($user->id, $mediaAsset->uploaded_by_user_id);
        $this->assertNull($mediaAsset->width);
        $this->assertNull($mediaAsset->height);
        $this->assertNull($mediaAsset->duration_ms);
        $this->assertNotNull($mediaAsset->checksum_sha256);
        Storage::disk('scratchpad')->assertExists($mediaAsset->path);

        $attachment = Attachment::sole();
        $this->assertSame($entry->id, $attachment->attachable_id);
        $this->assertSame($entry->getMorphClass(), $attachment->attachable_type);
        $this->assertSame($mediaAsset->id, $attachment->media_asset_id);
        $this->assertSame('audio', $attachment->role);
        $this->assertSame(0, $attachment->position);
    }

    public function test_a_duplicate_upload_reuses_the_existing_media_asset()
    {
        Storage::fake('scratchpad');

        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();

        $action = new CaptureScratchpadVoiceAction;

        $first = $action->handle(
            $workspace,
            $user,
            new CaptureScratchpadVoiceData(file: UploadedFile::fake()->create('note.weba', 50, 'audio/webm'), language: 'bn'),
        );

        $second = $action->handle(
            $workspace,
            $user,
            new CaptureScratchpadVoiceData(file: UploadedFile::fake()->create('note.weba', 50, 'audio/webm'), language: 'en'),
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

        $entry = (new CaptureScratchpadVoiceAction)->handle(
            $workspace,
            $user,
            new CaptureScratchpadVoiceData(file: UploadedFile::fake()->create('note.weba', 50, 'audio/webm'), language: null),
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

    public function test_a_telegram_capture_has_no_uploading_user_and_records_a_system_actor()
    {
        Storage::fake('scratchpad');

        $workspace = Workspace::factory()->create();
        $file = UploadedFile::fake()->create('telegram-voice.ogg', 50, 'audio/ogg');

        $entry = (new CaptureScratchpadVoiceAction)->handle(
            $workspace,
            null,
            CaptureScratchpadVoiceData::fromTelegram($file),
        );

        $this->assertSame('telegram', $entry->source);
        $this->assertNull(MediaAsset::sole()->uploaded_by_user_id);
        $this->assertDatabaseHas('status_transitions', [
            'subject_type' => $entry->getMorphClass(),
            'subject_id' => $entry->id,
            'actor_type' => 'system',
            'actor_id' => null,
        ]);
    }
}
