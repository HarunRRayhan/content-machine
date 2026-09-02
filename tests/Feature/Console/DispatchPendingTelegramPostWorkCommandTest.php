<?php

namespace Tests\Feature\Console;

use App\Jobs\GenerateTelegramPostJob;
use App\Jobs\ResolveScratchpadLinkJob;
use App\Jobs\TranscribeVoiceNoteJob;
use App\Models\MediaAsset;
use App\Models\ScratchpadEntry;
use App\Models\TelegramBotConfig;
use App\Models\TelegramPostRequest;
use App\Models\Transcription;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DispatchPendingTelegramPostWorkCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requeues_generation_and_enrichment_for_stuck_requests(): void
    {
        Queue::fake();
        $workspace = Workspace::factory()->create();
        $config = TelegramBotConfig::factory()->for($workspace)->connected()->create();
        $textEntry = ScratchpadEntry::factory()->for($workspace)->create([
            'kind' => 'text',
            'source' => 'telegram',
        ]);
        $linkEntry = ScratchpadEntry::factory()->for($workspace)->create([
            'kind' => 'link',
            'source' => 'telegram',
            'body' => 'https://example.com',
            'meta' => ['url' => 'https://example.com'],
        ]);
        TelegramPostRequest::factory()->for($workspace)->create([
            'telegram_bot_config_id' => $config->id,
            'source_scratchpad_entry_id' => $textEntry->id,
            'telegram_user_id' => 1,
            'telegram_chat_id' => 1,
            'state' => TelegramPostRequest::GENERATING,
        ]);
        TelegramPostRequest::factory()->for($workspace)->create([
            'telegram_bot_config_id' => $config->id,
            'source_scratchpad_entry_id' => $linkEntry->id,
            'telegram_user_id' => 1,
            'telegram_chat_id' => 1,
            'state' => TelegramPostRequest::GENERATING,
        ]);

        $this->artisan('telegram:dispatch-pending-post-work')
            ->assertSuccessful()
            ->expectsOutput('Dispatched 2 pending Telegram post work item(s).');

        Queue::assertPushed(GenerateTelegramPostJob::class);
        Queue::assertPushed(ResolveScratchpadLinkJob::class);
    }

    public function test_it_recovers_a_telegram_transcription_with_only_a_cancelled_request(): void
    {
        Queue::fake();
        $workspace = Workspace::factory()->create();
        $config = TelegramBotConfig::factory()->for($workspace)->connected()->create();
        $entry = ScratchpadEntry::factory()->for($workspace)->create([
            'kind' => 'voice',
            'source' => 'telegram',
        ]);
        $mediaAsset = MediaAsset::factory()->for($workspace)->create(['kind' => 'audio']);
        $transcription = Transcription::factory()->create([
            'scratchpad_entry_id' => $entry->id,
            'media_asset_id' => $mediaAsset->id,
            'status' => 'pending',
        ]);
        TelegramPostRequest::factory()->for($workspace)->create([
            'telegram_bot_config_id' => $config->id,
            'source_scratchpad_entry_id' => $entry->id,
            'telegram_user_id' => 1,
            'telegram_chat_id' => 1,
            'state' => TelegramPostRequest::CANCELLED,
        ]);

        $this->artisan('telegram:dispatch-pending-post-work')
            ->assertSuccessful()
            ->expectsOutput('Dispatched 1 pending Telegram post work item(s).');

        Queue::assertPushed(TranscribeVoiceNoteJob::class, fn (TranscribeVoiceNoteJob $job): bool => $job->transcription->is($transcription));
    }

    public function test_a_live_request_lease_does_not_starve_a_later_request(): void
    {
        Queue::fake();
        $workspace = Workspace::factory()->create();
        $config = TelegramBotConfig::factory()->for($workspace)->connected()->create();
        $firstEntry = ScratchpadEntry::factory()->for($workspace)->create([
            'kind' => 'text',
            'source' => 'telegram',
        ]);
        $secondEntry = ScratchpadEntry::factory()->for($workspace)->create([
            'kind' => 'text',
            'source' => 'telegram',
        ]);
        TelegramPostRequest::factory()->for($workspace)->create([
            'telegram_bot_config_id' => $config->id,
            'source_scratchpad_entry_id' => $firstEntry->id,
            'telegram_user_id' => 1,
            'telegram_chat_id' => 1,
            'state' => TelegramPostRequest::GENERATING,
            'work_claimed_at' => now(),
            'work_lease_id' => '72d9c4a1-58b0-4be7-95c0-a1d2227d2f22',
        ]);
        $laterRequest = TelegramPostRequest::factory()->for($workspace)->create([
            'telegram_bot_config_id' => $config->id,
            'source_scratchpad_entry_id' => $secondEntry->id,
            'telegram_user_id' => 1,
            'telegram_chat_id' => 1,
            'state' => TelegramPostRequest::GENERATING,
        ]);

        $this->artisan('telegram:dispatch-pending-post-work', ['--limit' => 1])
            ->assertSuccessful()
            ->expectsOutput('Dispatched 1 pending Telegram post work item(s).');

        Queue::assertPushed(GenerateTelegramPostJob::class, fn (GenerateTelegramPostJob $job): bool => $job->telegramPostRequestId === $laterRequest->id);
    }

    public function test_it_recovers_a_standalone_telegram_link_without_a_post_request(): void
    {
        Queue::fake();
        $workspace = Workspace::factory()->create();
        $entry = ScratchpadEntry::factory()->for($workspace)->create([
            'kind' => 'link',
            'source' => 'telegram',
            'body' => 'https://example.com',
            'meta' => ['url' => 'https://example.com'],
        ]);

        $this->artisan('telegram:dispatch-pending-post-work')
            ->assertSuccessful()
            ->expectsOutput('Dispatched 1 pending Telegram post work item(s).');

        Queue::assertPushed(ResolveScratchpadLinkJob::class, fn (ResolveScratchpadLinkJob $job): bool => $job->entry->is($entry));
    }

    public function test_an_active_post_request_does_not_starve_a_standalone_transcription(): void
    {
        Queue::fake();
        $workspace = Workspace::factory()->create();
        $config = TelegramBotConfig::factory()->for($workspace)->connected()->create();
        $activeEntry = ScratchpadEntry::factory()->for($workspace)->create([
            'kind' => 'voice',
            'source' => 'telegram',
        ]);
        $laterEntry = ScratchpadEntry::factory()->for($workspace)->create([
            'kind' => 'voice',
            'source' => 'telegram',
        ]);
        $activeMedia = MediaAsset::factory()->for($workspace)->create(['kind' => 'audio']);
        $laterMedia = MediaAsset::factory()->for($workspace)->create(['kind' => 'audio']);
        $activeTranscription = Transcription::factory()->create([
            'scratchpad_entry_id' => $activeEntry->id,
            'media_asset_id' => $activeMedia->id,
            'status' => 'pending',
        ]);
        $laterTranscription = Transcription::factory()->create([
            'scratchpad_entry_id' => $laterEntry->id,
            'media_asset_id' => $laterMedia->id,
            'status' => 'pending',
        ]);
        TelegramPostRequest::factory()->for($workspace)->create([
            'telegram_bot_config_id' => $config->id,
            'source_scratchpad_entry_id' => $activeEntry->id,
            'telegram_user_id' => 1,
            'telegram_chat_id' => 1,
            'state' => TelegramPostRequest::GENERATING,
            'work_claimed_at' => now(),
            'work_lease_id' => '72d9c4a1-58b0-4be7-95c0-a1d2227d2f22',
        ]);

        $this->artisan('telegram:dispatch-pending-post-work', ['--limit' => 1])
            ->assertSuccessful()
            ->expectsOutput('Dispatched 1 pending Telegram post work item(s).');

        Queue::assertPushed(TranscribeVoiceNoteJob::class, fn (TranscribeVoiceNoteJob $job): bool => $job->transcription->is($laterTranscription));
        Queue::assertNotPushed(TranscribeVoiceNoteJob::class, fn (TranscribeVoiceNoteJob $job): bool => $job->transcription->is($activeTranscription));
    }
}
