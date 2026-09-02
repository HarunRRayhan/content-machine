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
}
