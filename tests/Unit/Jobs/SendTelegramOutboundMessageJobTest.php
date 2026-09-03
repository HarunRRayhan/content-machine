<?php

namespace Tests\Unit\Jobs;

use App\Actions\Telegram\SendTelegramOutboundMessageAction;
use App\Jobs\SendTelegramOutboundMessageJob;
use App\Models\TelegramBotConfig;
use App\Models\TelegramOutboundMessage;
use App\Support\Telegram\TelegramApiResult;
use App\Support\Telegram\TelegramClientContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\Telegram\FakeTelegramClient;
use Tests\TestCase;

class SendTelegramOutboundMessageJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_one_chunk_and_marks_the_message_sent(): void
    {
        Queue::fake();
        $config = TelegramBotConfig::factory()->connected()->create(['bot_token' => '123:tok']);
        $message = TelegramOutboundMessage::factory()->create([
            'telegram_bot_config_id' => $config->id,
            'webhook_generation' => $config->webhook_generation,
            'chat_id' => 555,
            'chunks' => ['hello'],
        ]);
        $client = new FakeTelegramClient;
        $this->app->instance(TelegramClientContract::class, $client);

        (new SendTelegramOutboundMessageJob($message->id))->handle(app(SendTelegramOutboundMessageAction::class));

        $this->assertSame([['botToken' => '123:tok', 'chatId' => 555, 'text' => 'hello']], $client->sentMessages);
        $this->assertSame(TelegramOutboundMessage::SENT, $message->refresh()->status);
        $this->assertSame(1, $message->next_chunk);
    }

    public function test_it_resumes_from_the_next_chunk(): void
    {
        Queue::fake();
        $config = TelegramBotConfig::factory()->connected()->create();
        $message = TelegramOutboundMessage::factory()->create([
            'telegram_bot_config_id' => $config->id,
            'webhook_generation' => $config->webhook_generation,
            'chunks' => ['already sent', 'remaining'],
            'next_chunk' => 1,
        ]);
        $client = new FakeTelegramClient;
        $this->app->instance(TelegramClientContract::class, $client);

        (new SendTelegramOutboundMessageJob($message->id))->handle(app(SendTelegramOutboundMessageAction::class));

        $this->assertSame('remaining', $client->sentMessages[0]['text']);
        $this->assertSame(TelegramOutboundMessage::SENT, $message->refresh()->status);
    }

    public function test_a_rate_limit_response_keeps_the_row_retryable(): void
    {
        Queue::fake();
        $config = TelegramBotConfig::factory()->connected()->create();
        $message = TelegramOutboundMessage::factory()->create([
            'telegram_bot_config_id' => $config->id,
            'webhook_generation' => $config->webhook_generation,
        ]);
        $client = (new FakeTelegramClient)->willSendMessage(TelegramApiResult::failure(
            'Too many requests.',
            retryAfterSeconds: 60,
            status: 429,
        ));
        $this->app->instance(TelegramClientContract::class, $client);

        (new SendTelegramOutboundMessageJob($message->id))->handle(app(SendTelegramOutboundMessageAction::class));

        $message->refresh();
        $this->assertSame(TelegramOutboundMessage::PENDING, $message->status);
        $this->assertSame(1, $message->attempts);
        $this->assertNotNull($message->next_attempt_at);
        $this->assertSame('Too many requests.', $message->last_error);
    }

    public function test_a_permanent_api_failure_is_terminal_and_not_requeued(): void
    {
        Queue::fake();
        $config = TelegramBotConfig::factory()->connected()->create();
        $message = TelegramOutboundMessage::factory()->create([
            'telegram_bot_config_id' => $config->id,
            'webhook_generation' => $config->webhook_generation,
        ]);
        $client = (new FakeTelegramClient)->willSendMessage(TelegramApiResult::failure(
            'Bad Request: chat not found',
            status: 400,
        ));
        $this->app->instance(TelegramClientContract::class, $client);

        (new SendTelegramOutboundMessageJob($message->id))->handle(app(SendTelegramOutboundMessageAction::class));

        $message->refresh();
        $this->assertSame(TelegramOutboundMessage::FAILED, $message->status);
        $this->assertSame('Bad Request: chat not found', $message->last_error);
        $this->assertNotNull($message->failed_at);

        $this->artisan('telegram:dispatch-pending-outbound-messages')
            ->assertSuccessful()
            ->expectsOutput('Dispatched 0 pending Telegram outbound message(s).');
        $this->artisan('telegram:dispatch-pending-outbound-messages')
            ->assertSuccessful()
            ->expectsOutput('Dispatched 0 pending Telegram outbound message(s).');
        Queue::assertNothingPushed();
    }

    public function test_a_transport_failure_marks_the_message_uncertain_instead_of_retrying_automatically(): void
    {
        Queue::fake();
        $config = TelegramBotConfig::factory()->connected()->create();
        $message = TelegramOutboundMessage::factory()->create([
            'telegram_bot_config_id' => $config->id,
            'webhook_generation' => $config->webhook_generation,
        ]);
        $client = (new FakeTelegramClient)->willSendMessage(TelegramApiResult::failure(
            'Could not reach Telegram to send the reply.',
            outcomeUnknown: true,
        ));
        $this->app->instance(TelegramClientContract::class, $client);

        (new SendTelegramOutboundMessageJob($message->id))->handle(app(SendTelegramOutboundMessageAction::class));

        $message->refresh();
        $this->assertSame(TelegramOutboundMessage::UNCERTAIN, $message->status);
        $this->assertNull($message->next_attempt_at);
        $this->assertStringContainsString('outcome is uncertain', $message->last_error);
        Queue::assertNothingPushed();
    }

    public function test_stale_generation_is_discarded_without_sending(): void
    {
        Queue::fake();
        $config = TelegramBotConfig::factory()->connected()->create();
        $message = TelegramOutboundMessage::factory()->create([
            'telegram_bot_config_id' => $config->id,
            'webhook_generation' => '00000000-0000-0000-0000-000000000000',
        ]);
        $client = new FakeTelegramClient;
        $this->app->instance(TelegramClientContract::class, $client);

        (new SendTelegramOutboundMessageJob($message->id))->handle(app(SendTelegramOutboundMessageAction::class));

        $this->assertSame([], $client->sentMessages);
        $this->assertSame(TelegramOutboundMessage::DISCARDED, $message->refresh()->status);
    }

    public function test_a_message_created_during_disconnect_is_discarded_without_retrying_forever(): void
    {
        Queue::fake();
        $config = TelegramBotConfig::factory()->connected()->create([
            'bot_token' => null,
        ]);
        $message = TelegramOutboundMessage::factory()->create([
            'telegram_bot_config_id' => $config->id,
            'webhook_generation' => $config->webhook_generation,
        ]);
        $client = new FakeTelegramClient;
        $this->app->instance(TelegramClientContract::class, $client);

        (new SendTelegramOutboundMessageJob($message->id))->handle(app(SendTelegramOutboundMessageAction::class));

        $message->refresh();
        $this->assertSame([], $client->sentMessages);
        $this->assertSame(TelegramOutboundMessage::DISCARDED, $message->status);
        $this->assertNotNull($message->discarded_at);
        $this->assertNull($message->next_attempt_at);
    }

    public function test_failed_does_not_overwrite_a_sent_row(): void
    {
        $message = TelegramOutboundMessage::factory()->create([
            'status' => TelegramOutboundMessage::SENT,
            'sent_at' => now(),
        ]);

        (new SendTelegramOutboundMessageJob($message->id))->failed(new RuntimeException('late failure'));

        $this->assertSame(TelegramOutboundMessage::SENT, $message->refresh()->status);
    }

    public function test_identity_lock_contention_releases_a_recovery_lease_without_failing_the_row(): void
    {
        Queue::fake();
        $config = TelegramBotConfig::factory()->connected()->create();
        $leaseId = (string) Str::uuid();
        $message = TelegramOutboundMessage::factory()->create([
            'telegram_bot_config_id' => $config->id,
            'webhook_generation' => $config->webhook_generation,
            'dispatch_claimed_at' => now(),
            'dispatch_lease_id' => $leaseId,
        ]);
        $client = new FakeTelegramClient;
        $this->app->instance(TelegramClientContract::class, $client);
        $lock = Cache::lock('telegram:bot-identity:workspace:'.$config->workspace_id, 120);
        $this->assertTrue($lock->get());

        try {
            (new SendTelegramOutboundMessageJob($message->id, $leaseId))
                ->handle(app(SendTelegramOutboundMessageAction::class));
        } finally {
            $lock->release();
        }

        $message->refresh();
        $this->assertSame(TelegramOutboundMessage::PENDING, $message->status);
        $this->assertNull($message->dispatch_claimed_at);
        $this->assertNull($message->dispatch_lease_id);
        $this->assertSame([], $client->sentMessages);
    }
}
