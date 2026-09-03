<?php

namespace Tests\Feature\Telegram;

use App\Jobs\ProcessTelegramUpdateJob;
use App\Models\TelegramBotConfig;
use App\Models\TelegramUpdate;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class TelegramWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private function payload(int $update = 1): array
    {
        return [
            'update_id' => $update,
            'message' => [
                'chat' => ['id' => 555, 'type' => 'private'],
                'from' => ['id' => 42],
                'text' => 'hello',
            ],
        ];
    }

    public function test_an_unknown_slug_404s()
    {
        $this->postJson(route('telegram.webhook', ['slug' => 'no-such-slug']), $this->payload())
            ->assertNotFound();
    }

    public function test_a_missing_secret_header_is_rejected()
    {
        $config = TelegramBotConfig::factory()->connected()->create();

        $this->postJson(route('telegram.webhook', ['slug' => $config->webhook_slug]), $this->payload())
            ->assertForbidden();
    }

    public function test_a_wrong_secret_header_is_rejected()
    {
        $config = TelegramBotConfig::factory()->connected()->create();

        $this->postJson(
            route('telegram.webhook', ['slug' => $config->webhook_slug]),
            $this->payload(),
            ['X-Telegram-Bot-Api-Secret-Token' => 'not-the-right-secret'],
        )->assertForbidden();
    }

    public function test_a_link_update_uses_the_default_queue_and_returns_no_content()
    {
        Queue::fake();
        $config = TelegramBotConfig::factory()->connected()->create();
        $payload = $this->payload(update: 100);
        $payload['message']['text'] = 'https://example.com';

        $response = $this->postJson(
            route('telegram.webhook', ['slug' => $config->webhook_slug]),
            $payload,
            ['X-Telegram-Bot-Api-Secret-Token' => $config->webhook_secret],
        );

        $response->assertNoContent();
        Queue::assertPushed(ProcessTelegramUpdateJob::class, fn (ProcessTelegramUpdateJob $job) => $job->telegramBotConfigId === $config->id
            && $job->update['update_id'] === 100
            && $job->webhookGeneration === $config->webhook_generation
            && $job->queue === 'default');
        $this->assertDatabaseHas('telegram_updates', [
            'telegram_bot_config_id' => $config->id,
            'update_id' => 100,
        ]);
    }

    public function test_a_media_update_uses_the_scratchpad_queue()
    {
        Queue::fake();
        $config = TelegramBotConfig::factory()->connected()->create();
        $payload = $this->payload(update: 101);
        unset($payload['message']['text']);
        $payload['message']['photo'] = [['file_id' => 'photo-file-id']];

        $this->postJson(
            route('telegram.webhook', ['slug' => $config->webhook_slug]),
            $payload,
            ['X-Telegram-Bot-Api-Secret-Token' => $config->webhook_secret],
        )->assertNoContent();

        Queue::assertPushed(ProcessTelegramUpdateJob::class, fn (ProcessTelegramUpdateJob $job) => $job->telegramBotConfigId === $config->id
            && $job->update['update_id'] === 101
            && $job->queue === 'scratchpad');
    }

    public function test_a_group_message_is_ignored_before_it_is_persisted_or_queued(): void
    {
        Queue::fake();
        $config = TelegramBotConfig::factory()->connected()->create();
        $payload = $this->payload(update: 103);
        $payload['message']['chat']['type'] = 'supergroup';

        $this->postJson(
            route('telegram.webhook', ['slug' => $config->webhook_slug]),
            $payload,
            ['X-Telegram-Bot-Api-Secret-Token' => $config->webhook_secret],
        )->assertNoContent();

        Queue::assertNothingPushed();
        $this->assertDatabaseMissing('telegram_updates', [
            'telegram_bot_config_id' => $config->id,
            'update_id' => 103,
        ]);
    }

    public function test_a_queue_failure_does_not_make_telegram_retry_the_webhook(): void
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $this->mock(Dispatcher::class, function (MockInterface $dispatcher): void {
            $dispatcher->shouldReceive('dispatch')
                ->once()
                ->andThrow(new RuntimeException('queue unavailable'));
        });

        $response = $this->postJson(
            route('telegram.webhook', ['slug' => $config->webhook_slug]),
            $this->payload(update: 104),
            ['X-Telegram-Bot-Api-Secret-Token' => $config->webhook_secret],
        );

        $response->assertNoContent();
        $this->assertDatabaseHas('telegram_updates', [
            'telegram_bot_config_id' => $config->id,
            'update_id' => 104,
            'processed_at' => null,
            'failed_at' => null,
        ]);
    }

    public function test_a_retry_reopens_a_missing_payload_failure_and_dispatches_the_update(): void
    {
        Queue::fake();
        $config = TelegramBotConfig::factory()->connected()->create();
        $payload = $this->payload(update: 105);
        $update = TelegramUpdate::create([
            'telegram_bot_config_id' => $config->id,
            'webhook_generation' => $config->webhook_generation,
            'update_id' => 105,
            'failed_at' => now(),
            'last_error' => ProcessTelegramUpdateJob::MISSING_PAYLOAD_ERROR,
        ]);

        $this->postJson(
            route('telegram.webhook', ['slug' => $config->webhook_slug]),
            $payload,
            ['X-Telegram-Bot-Api-Secret-Token' => $config->webhook_secret],
        )->assertNoContent();

        $update->refresh();
        $this->assertEquals($payload, $update->payload);
        $this->assertNull($update->failed_at);
        $this->assertNull($update->last_error);
        Queue::assertPushed(ProcessTelegramUpdateJob::class, fn (ProcessTelegramUpdateJob $job): bool => $job->telegramBotConfigId === $config->id
            && $job->update['update_id'] === 105
            && $job->webhookGeneration === $config->webhook_generation);
    }

    public function test_a_voice_update_uses_the_scratchpad_queue()
    {
        Queue::fake();
        $config = TelegramBotConfig::factory()->connected()->create();
        $payload = $this->payload(update: 102);
        unset($payload['message']['text']);
        $payload['message']['voice'] = ['file_id' => 'voice-file-id'];

        $this->postJson(
            route('telegram.webhook', ['slug' => $config->webhook_slug]),
            $payload,
            ['X-Telegram-Bot-Api-Secret-Token' => $config->webhook_secret],
        )->assertNoContent();

        Queue::assertPushed(ProcessTelegramUpdateJob::class, fn (ProcessTelegramUpdateJob $job) => $job->telegramBotConfigId === $config->id
            && $job->update['update_id'] === 102
            && $job->queue === 'scratchpad');
    }

    public function test_a_disconnected_config_accepts_but_does_not_dispatch()
    {
        Queue::fake();
        $config = TelegramBotConfig::factory()->connected()->create();
        $secret = $config->webhook_secret;
        $config->update(['bot_token' => null]);

        $response = $this->postJson(
            route('telegram.webhook', ['slug' => $config->webhook_slug]),
            $this->payload(),
            ['X-Telegram-Bot-Api-Secret-Token' => $secret],
        );

        $response->assertNoContent();
        Queue::assertNotPushed(ProcessTelegramUpdateJob::class);
        $this->assertSame(0, TelegramUpdate::count());
    }

    public function test_a_redelivered_update_id_is_not_dispatched_twice()
    {
        Queue::fake();
        $config = TelegramBotConfig::factory()->connected()->create();

        $headers = ['X-Telegram-Bot-Api-Secret-Token' => $config->webhook_secret];
        $url = route('telegram.webhook', ['slug' => $config->webhook_slug]);

        $this->postJson($url, $this->payload(update: 7), $headers)->assertNoContent();
        $this->postJson($url, $this->payload(update: 7), $headers)->assertNoContent();

        Queue::assertPushed(ProcessTelegramUpdateJob::class, 1);
        $this->assertSame(1, TelegramUpdate::where('update_id', 7)->count());
    }

    public function test_an_update_id_remains_fenced_until_the_bot_connection_is_rotated(): void
    {
        Queue::fake();
        $config = TelegramBotConfig::factory()->connected()->create();
        $headers = ['X-Telegram-Bot-Api-Secret-Token' => $config->webhook_secret];
        $url = route('telegram.webhook', ['slug' => $config->webhook_slug]);

        $this->postJson($url, $this->payload(update: 7), $headers)->assertNoContent();

        $config->update(['webhook_generation' => (string) Str::uuid()]);
        $config->refresh();

        $this->postJson($url, $this->payload(update: 7), $headers)->assertNoContent();

        $this->assertSame(2, TelegramUpdate::where('telegram_bot_config_id', $config->id)
            ->where('update_id', 7)
            ->count());
        Queue::assertPushed(ProcessTelegramUpdateJob::class, 2);
    }

    public function test_a_rotated_update_reuses_the_legacy_row_before_the_global_fence_is_removed(): void
    {
        Queue::fake();
        $config = TelegramBotConfig::factory()->connected()->create();
        $headers = ['X-Telegram-Bot-Api-Secret-Token' => $config->webhook_secret];
        $url = route('telegram.webhook', ['slug' => $config->webhook_slug]);
        $legacyIndex = 'telegram_updates_legacy_fence_test_unique';

        $this->postJson($url, $this->payload(update: 8), $headers)->assertNoContent();
        $config->update(['webhook_generation' => (string) Str::uuid()]);
        DB::statement("CREATE UNIQUE INDEX {$legacyIndex} ON telegram_updates (telegram_bot_config_id, update_id)");

        try {
            $this->postJson($url, $this->payload(update: 8), $headers)->assertNoContent();
        } finally {
            DB::statement("DROP INDEX IF EXISTS {$legacyIndex}");
        }

        $this->assertSame(1, TelegramUpdate::where('telegram_bot_config_id', $config->id)
            ->where('update_id', 8)
            ->count());
        $this->assertSame($config->fresh()->webhook_generation, TelegramUpdate::query()->sole()->webhook_generation);
        Queue::assertPushed(ProcessTelegramUpdateJob::class, 2);
    }

    public function test_a_terminal_legacy_row_is_not_replayed_before_the_global_fence_is_removed(): void
    {
        Queue::fake();
        $config = TelegramBotConfig::factory()->connected()->create();
        $headers = ['X-Telegram-Bot-Api-Secret-Token' => $config->webhook_secret];
        $url = route('telegram.webhook', ['slug' => $config->webhook_slug]);
        $payload = $this->payload(update: 9);
        $legacyIndex = 'telegram_updates_terminal_legacy_fence_test_unique';
        $terminalAt = now();
        $oldGeneration = $config->webhook_generation;
        $config->update(['webhook_generation' => (string) Str::uuid()]);

        DB::table('telegram_updates')->insert([
            'telegram_bot_config_id' => $config->id,
            'webhook_generation' => $oldGeneration,
            'update_id' => 9,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'processed_at' => $terminalAt,
            'created_at' => $terminalAt,
            'updated_at' => $terminalAt,
        ]);
        DB::statement("CREATE UNIQUE INDEX {$legacyIndex} ON telegram_updates (telegram_bot_config_id, update_id)");

        try {
            $this->postJson($url, $payload, $headers)->assertNoContent();
        } finally {
            DB::statement("DROP INDEX IF EXISTS {$legacyIndex}");
        }

        $this->assertSame(1, TelegramUpdate::where('telegram_bot_config_id', $config->id)->count());
        $row = TelegramUpdate::query()->sole();
        $this->assertSame($oldGeneration, $row->webhook_generation);
        $this->assertNotNull($row->processed_at);
        Queue::assertNothingPushed();
    }

    public function test_an_update_without_a_valid_update_id_is_rejected(): void
    {
        Queue::fake();
        $config = TelegramBotConfig::factory()->connected()->create();

        $payload = $this->payload();
        unset($payload['update_id']);

        $this->postJson(
            route('telegram.webhook', ['slug' => $config->webhook_slug]),
            $payload,
            ['X-Telegram-Bot-Api-Secret-Token' => $config->webhook_secret],
        )->assertUnprocessable();

        Queue::assertNothingPushed();
        $this->assertSame(0, TelegramUpdate::count());
    }
}
