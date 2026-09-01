<?php

namespace Tests\Feature\Console;

use App\Jobs\GenerateTelegramPostJob;
use App\Jobs\ResolveScratchpadLinkJob;
use App\Models\ScratchpadEntry;
use App\Models\TelegramBotConfig;
use App\Models\TelegramPostRequest;
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
}
