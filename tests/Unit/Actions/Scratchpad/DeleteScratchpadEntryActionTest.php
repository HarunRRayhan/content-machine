<?php

namespace Tests\Unit\Actions\Scratchpad;

use App\Actions\Scratchpad\DeleteScratchpadEntryAction;
use App\Models\Attachment;
use App\Models\MediaAsset;
use App\Models\ScratchpadEntry;
use App\Models\StatusTransition;
use App\Models\Transcription;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class DeleteScratchpadEntryActionTest extends TestCase
{
    use RefreshDatabase;

    private function action(): DeleteScratchpadEntryAction
    {
        return new DeleteScratchpadEntryAction;
    }

    public function test_a_new_entry_is_deleted()
    {
        $entry = ScratchpadEntry::factory()->create();

        $this->action()->handle($entry);

        $this->assertSame(0, ScratchpadEntry::count());
    }

    public function test_a_dropped_entry_is_deleted()
    {
        $entry = ScratchpadEntry::factory()->dropped()->create();

        $this->action()->handle($entry);

        $this->assertSame(0, ScratchpadEntry::count());
    }

    public function test_a_triaged_entry_is_refused()
    {
        $entry = ScratchpadEntry::factory()->triaged()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("already been triaged into an idea and can't be deleted");

        $this->action()->handle($entry);
    }

    public function test_its_status_transitions_are_deleted_too()
    {
        $entry = ScratchpadEntry::factory()->create();
        $entry->recordStatusTransition(null, 'new');

        $this->action()->handle($entry);

        $this->assertSame(0, StatusTransition::count());
    }

    public function test_its_transcription_is_deleted_too()
    {
        $entry = ScratchpadEntry::factory()->create();
        Transcription::factory()->create(['scratchpad_entry_id' => $entry->id]);

        $this->action()->handle($entry);

        $this->assertSame(0, Transcription::count());
    }

    public function test_its_attachment_and_unshared_media_file_are_deleted_too()
    {
        Storage::fake('local');

        $workspace = Workspace::factory()->create();
        $entry = ScratchpadEntry::factory()->for($workspace)->create();
        $mediaAsset = MediaAsset::factory()->for($workspace)->create(['path' => 'media/photo.jpg']);
        Storage::disk('local')->put('media/photo.jpg', 'fake bytes');
        Attachment::factory()->for($entry, 'attachable')->create(['media_asset_id' => $mediaAsset->id]);

        $this->action()->handle($entry);

        $this->assertSame(0, Attachment::count());
        $this->assertSame(0, MediaAsset::count());
        Storage::disk('local')->assertMissing('media/photo.jpg');
    }

    public function test_a_media_asset_still_attached_to_another_entry_is_kept()
    {
        Storage::fake('local');

        $workspace = Workspace::factory()->create();
        $entry = ScratchpadEntry::factory()->for($workspace)->create();
        $otherEntry = ScratchpadEntry::factory()->for($workspace)->create();
        $mediaAsset = MediaAsset::factory()->for($workspace)->create(['path' => 'media/shared.jpg']);
        Storage::disk('local')->put('media/shared.jpg', 'fake bytes');
        Attachment::factory()->for($entry, 'attachable')->create(['media_asset_id' => $mediaAsset->id]);
        Attachment::factory()->for($otherEntry, 'attachable')->create(['media_asset_id' => $mediaAsset->id]);

        $this->action()->handle($entry);

        $this->assertSame(1, Attachment::count());
        $this->assertSame(1, MediaAsset::count());
        Storage::disk('local')->assertExists('media/shared.jpg');
    }
}
