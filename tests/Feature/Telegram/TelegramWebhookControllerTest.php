<?php

namespace Tests\Feature\Telegram;

use App\Jobs\ProcessTelegramUpdateJob;
use App\Models\TelegramBotConfig;
use App\Models\TelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class TelegramWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private function payload(int $update = 1): array
    {
        return [
            'update_id' => $update,
            'message' => [
                'chat' => ['id' => 555],
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
