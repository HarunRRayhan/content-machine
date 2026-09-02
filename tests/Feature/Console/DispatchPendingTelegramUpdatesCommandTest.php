<?php

namespace Tests\Feature\Console;

use App\Jobs\ProcessTelegramUpdateJob;
use App\Models\TelegramBotConfig;
use App\Models\TelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DispatchPendingTelegramUpdatesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_only_unprocessed_updates_with_payloads(): void
    {
        Queue::fake();
        $config = TelegramBotConfig::factory()->connected()->create();
        $payload = [
            'update_id' => 77,
            'message' => [
                'chat' => ['id' => 555],
                'from' => ['id' => 42],
                'text' => 'pending',
            ],
        ];

        TelegramUpdate::create([
            'telegram_bot_config_id' => $config->id,
            'update_id' => 77,
            'payload' => $payload,
        ]);
        TelegramUpdate::create([
            'telegram_bot_config_id' => $config->id,
            'update_id' => 78,
            'payload' => $payload,
            'processed_at' => now(),
        ]);

        $this->artisan('telegram:dispatch-pending-updates')
            ->assertSuccessful()
            ->expectsOutput('Dispatched 1 pending Telegram update(s).');

        Queue::assertPushed(ProcessTelegramUpdateJob::class, fn (ProcessTelegramUpdateJob $job): bool => $job->telegramBotConfigId === $config->id
            && $job->update['update_id'] === 77);
    }

    public function test_it_marks_malformed_payloads_as_failed_instead_of_claiming_them_forever(): void
    {
        $config = TelegramBotConfig::factory()->connected()->create();

        TelegramUpdate::create([
            'telegram_bot_config_id' => $config->id,
            'update_id' => 100,
            'payload' => ['wrong_key' => true],
        ]);

        $this->artisan('telegram:dispatch-pending-updates')
            ->assertSuccessful()
            ->expectsOutput('Dispatched 0 pending Telegram update(s).');

        $this->assertDatabaseHas('telegram_updates', [
            'telegram_bot_config_id' => $config->id,
            'update_id' => 100,
            'last_error' => 'The stored Telegram update payload has no valid update id.',
        ]);
    }
}
