<?php

namespace Tests\Unit\Jobs;

use App\Actions\Scratchpad\TranscribeVoiceNoteAction;
use App\Jobs\TranscribeVoiceNoteJob;
use App\Models\MediaAsset;
use App\Models\Transcription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class TranscribeVoiceNoteJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_delegates_to_the_action()
    {
        $mediaAsset = MediaAsset::factory()->create();
        $transcription = Transcription::factory()->create(['media_asset_id' => $mediaAsset->id]);

        $action = Mockery::mock(TranscribeVoiceNoteAction::class);
        $action->shouldReceive('handle')->once()->with(Mockery::on(
            fn (Transcription $t) => $t->is($transcription)
        ));

        (new TranscribeVoiceNoteJob($transcription))->handle($action);
    }
}
