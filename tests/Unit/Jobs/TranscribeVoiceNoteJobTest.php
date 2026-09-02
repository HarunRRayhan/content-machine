<?php

namespace Tests\Unit\Jobs;

use App\Actions\Scratchpad\TranscribeVoiceNoteAction;
use App\Jobs\TranscribeVoiceNoteJob;
use App\Models\MediaAsset;
use App\Models\ScratchpadEntry;
use App\Models\TelegramBotConfig;
use App\Models\TelegramPostRequest;
use App\Models\Transcription;
use App\Models\Workspace;
use App\Support\Telegram\TelegramClientContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\Support\Telegram\FakeTelegramClient;
use Tests\TestCase;

class TranscribeVoiceNoteJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_delegates_to_the_action()
    {
        $mediaAsset = MediaAsset::factory()->create();
        $transcription = Transcription::factory()->create(['media_asset_id' => $mediaAsset->id]);

        $action = Mockery::mock(TranscribeVoiceNoteAction::class);
        $action->shouldReceive('handle')->once()->with(
            Mockery::on(fn (Transcription $t) => $t->is($transcription)),
            null,
            null,
        );

        (new TranscribeVoiceNoteJob($transcription))->handle($action);
    }

    public function test_duplicate_recovery_jobs_share_a_unique_key(): void
    {
        $mediaAsset = MediaAsset::factory()->create();
        $transcription = Transcription::factory()->create(['media_asset_id' => $mediaAsset->id]);

        $job = new TranscribeVoiceNoteJob($transcription);

        $this->assertSame('voice-transcription:'.$transcription->id, $job->uniqueId());
        $this->assertSame(TranscribeVoiceNoteJob::UNIQUE_FOR_SECONDS, $job->uniqueFor());
    }

    public function test_failed_marks_a_telegram_post_request_as_failed(): void
    {
        $workspace = Workspace::factory()->create();
        $config = TelegramBotConfig::factory()->connected()->create([
            'workspace_id' => $workspace->id,
        ]);
        $entry = ScratchpadEntry::factory()->create([
            'workspace_id' => $workspace->id,
            'kind' => 'voice',
            'source' => 'telegram',
        ]);
        $mediaAsset = MediaAsset::factory()->create(['workspace_id' => $workspace->id]);
        $transcription = Transcription::factory()->create([
            'scratchpad_entry_id' => $entry->id,
            'media_asset_id' => $mediaAsset->id,
            'status' => 'processing',
        ]);
        $request = TelegramPostRequest::factory()->create([
            'workspace_id' => $workspace->id,
            'telegram_bot_config_id' => $config->id,
            'source_scratchpad_entry_id' => $entry->id,
            'telegram_user_id' => 42,
            'telegram_chat_id' => 555,
            'state' => TelegramPostRequest::GENERATING,
        ]);
        $client = new FakeTelegramClient;
        $this->app->instance(TelegramClientContract::class, $client);

        (new TranscribeVoiceNoteJob($transcription))->failed(new RuntimeException('provider crashed'));

        $this->assertSame('failed', $transcription->refresh()->status);
        $this->assertSame(TelegramPostRequest::FAILED, $request->refresh()->state);
        $this->assertStringContainsString('could not transcribe', $client->sentMessages[0]['text']);
    }

    public function test_a_queued_transcription_finishes_as_source_enrichment_after_request_cancellation(): void
    {
        Queue::fake();
        $workspace = Workspace::factory()->create();
        $config = TelegramBotConfig::factory()->connected()->create([
            'workspace_id' => $workspace->id,
        ]);
        $entry = ScratchpadEntry::factory()->create([
            'workspace_id' => $workspace->id,
            'kind' => 'voice',
            'source' => 'telegram',
        ]);
        $mediaAsset = MediaAsset::factory()->create(['workspace_id' => $workspace->id]);
        $transcription = Transcription::factory()->create([
            'scratchpad_entry_id' => $entry->id,
            'media_asset_id' => $mediaAsset->id,
        ]);
        $leaseId = '72d9c4a1-58b0-4be7-95c0-a1d2227d2f22';
        $request = TelegramPostRequest::factory()->create([
            'workspace_id' => $workspace->id,
            'telegram_bot_config_id' => $config->id,
            'source_scratchpad_entry_id' => $entry->id,
            'telegram_user_id' => 42,
            'telegram_chat_id' => 555,
            'state' => TelegramPostRequest::CANCELLED,
            'work_claimed_at' => now(),
            'work_lease_id' => $leaseId,
        ]);

        $action = Mockery::mock(TranscribeVoiceNoteAction::class);
        $action->shouldReceive('handle')->once()->with(
            Mockery::on(fn (Transcription $t) => $t->is($transcription)),
            null,
            null,
        );

        (new TranscribeVoiceNoteJob($transcription, $request->id, $leaseId))->handle($action);

        $this->assertNull($request->refresh()->work_lease_id);
        $this->assertNull($request->work_claimed_at);
    }

    public function test_a_legacy_queued_transcription_does_not_clear_another_workers_cancellation_lease(): void
    {
        $workspace = Workspace::factory()->create();
        $config = TelegramBotConfig::factory()->connected()->create([
            'workspace_id' => $workspace->id,
        ]);
        $entry = ScratchpadEntry::factory()->create([
            'workspace_id' => $workspace->id,
            'kind' => 'voice',
            'source' => 'telegram',
        ]);
        $mediaAsset = MediaAsset::factory()->create(['workspace_id' => $workspace->id]);
        $transcription = Transcription::factory()->create([
            'scratchpad_entry_id' => $entry->id,
            'media_asset_id' => $mediaAsset->id,
        ]);
        $leaseId = '72d9c4a1-58b0-4be7-95c0-a1d2227d2f22';
        $request = TelegramPostRequest::factory()->create([
            'workspace_id' => $workspace->id,
            'telegram_bot_config_id' => $config->id,
            'source_scratchpad_entry_id' => $entry->id,
            'telegram_user_id' => 42,
            'telegram_chat_id' => 555,
            'state' => TelegramPostRequest::CANCELLED,
            'work_claimed_at' => now(),
            'work_lease_id' => $leaseId,
        ]);

        $action = Mockery::mock(TranscribeVoiceNoteAction::class);
        $action->shouldReceive('handle')->once()->with(
            Mockery::on(fn (Transcription $t) => $t->is($transcription)),
            null,
            null,
        );

        (new TranscribeVoiceNoteJob($transcription))->handle($action);

        $request->refresh();
        $this->assertSame($leaseId, $request->work_lease_id);
        $this->assertNotNull($request->work_claimed_at);
    }
}
