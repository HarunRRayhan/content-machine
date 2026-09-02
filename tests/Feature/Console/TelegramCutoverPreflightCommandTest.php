<?php

namespace Tests\Feature\Console;

use App\Models\TelegramBotConfig;
use App\Models\TelegramOutboundMessage;
use App\Models\TelegramUpdate;
use App\Support\Telegram\TelegramClientContract;
use App\Support\Telegram\TelegramWebhookInfoResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Telegram\FakeTelegramClient;
use Tests\TestCase;

class TelegramCutoverPreflightCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_passes_when_no_telegram_work_is_in_flight(): void
    {
        $this->artisan('telegram:cutover-preflight')
            ->assertSuccessful()
            ->expectsOutput('Telegram cutover preflight passed.');
    }

    public function test_it_fails_when_an_update_is_still_unprocessed(): void
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        TelegramUpdate::create([
            'telegram_bot_config_id' => $config->id,
            'update_id' => 7,
            'payload' => ['update_id' => 7],
        ]);

        $this->artisan('telegram:cutover-preflight')
            ->assertFailed()
            ->expectsOutput('FAIL unprocessed Telegram updates: 1')
            ->expectsOutput('Telegram cutover preflight failed. No data was changed.');
    }

    public function test_it_fails_when_a_telegram_update_job_is_still_queued(): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['data' => ['command' => 'App\\Jobs\\ProcessTelegramUpdateJob']], JSON_THROW_ON_ERROR),
            'attempts' => 0,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        $this->artisan('telegram:cutover-preflight')
            ->assertFailed()
            ->expectsOutput('FAIL queued Telegram update jobs: 1')
            ->expectsOutput('Telegram cutover preflight failed. No data was changed.');
    }

    public function test_historical_terminal_rows_from_an_older_generation_are_allowed(): void
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $oldGeneration = (string) Str::uuid();

        TelegramUpdate::create([
            'telegram_bot_config_id' => $config->id,
            'webhook_generation' => $oldGeneration,
            'update_id' => 9,
            'payload' => ['update_id' => 9],
            'processed_at' => now(),
        ]);
        TelegramOutboundMessage::factory()->create([
            'telegram_bot_config_id' => $config->id,
            'webhook_generation' => $oldGeneration,
            'status' => TelegramOutboundMessage::SENT,
            'sent_at' => now(),
        ]);

        $this->artisan('telegram:cutover-preflight')
            ->assertSuccessful()
            ->expectsOutput('Telegram cutover preflight passed.');
    }

    public function test_remote_webhook_verification_requires_the_current_url_and_an_empty_pending_count(): void
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $client = (new FakeTelegramClient)
            ->willGetWebhookInfo(TelegramWebhookInfoResult::success(
                route('telegram.webhook', ['slug' => $config->webhook_slug]),
                0,
            ));
        $this->app->instance(TelegramClientContract::class, $client);
        config(['app.telegram_old_web_fleet_drained' => true]);

        $this->artisan('telegram:cutover-preflight', [
            '--require-fleet-drained' => true,
            '--verify-remote-webhooks' => true,
        ])
            ->assertSuccessful()
            ->expectsOutput('Telegram cutover preflight passed.');
    }

    public function test_remote_webhook_verification_fails_for_a_stale_url(): void
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $client = (new FakeTelegramClient)
            ->willGetWebhookInfo(TelegramWebhookInfoResult::success('https://old.example/webhook', 0));
        $this->app->instance(TelegramClientContract::class, $client);
        config(['app.telegram_old_web_fleet_drained' => true]);

        $this->artisan('telegram:cutover-preflight', [
            '--require-fleet-drained' => true,
            '--verify-remote-webhooks' => true,
        ])
            ->assertFailed()
            ->expectsOutput('FAIL remote Telegram webhooks match the current URL with no pending updates: 1');
    }
}
