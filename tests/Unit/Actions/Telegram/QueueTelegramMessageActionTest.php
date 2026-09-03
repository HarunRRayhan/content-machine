<?php

namespace Tests\Unit\Actions\Telegram;

use App\Actions\Telegram\QueueTelegramMessageAction;
use App\Jobs\SendTelegramOutboundMessageJob;
use App\Models\TelegramBotConfig;
use App\Models\TelegramOutboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class QueueTelegramMessageActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_chunks_and_dispatches_one_delivery_job(): void
    {
        Queue::fake();
        $config = TelegramBotConfig::factory()->connected()->create();

        $message = (new QueueTelegramMessageAction)->handle(
            $config,
            -555,
            'hello',
            'telegram:update:key:reply:0',
        );

        $this->assertSame(TelegramOutboundMessage::PENDING, $message->status);
        $this->assertSame(['hello'], $message->chunks);
        $this->assertSame($config->webhook_generation, $message->webhook_generation);
        $this->assertDatabaseHas('telegram_outbound_messages', [
            'telegram_bot_config_id' => $config->id,
            'logical_key' => 'telegram:update:key:reply:0',
            'chat_id' => -555,
        ]);
        Queue::assertPushed(SendTelegramOutboundMessageJob::class, fn (SendTelegramOutboundMessageJob $job): bool => $job->telegramOutboundMessageId === $message->id
            && $job->queue === 'default');
    }

    public function test_it_splits_on_unicode_character_boundaries(): void
    {
        Queue::fake();
        $config = TelegramBotConfig::factory()->connected()->create();
        $text = str_repeat('ক', 4095).'🙂'.'শেষ';

        $message = (new QueueTelegramMessageAction)->handle($config, 555, $text, 'telegram:test:unicode');

        $this->assertCount(2, $message->chunks);
        $this->assertSame(4096, mb_strlen($message->chunks[0]));
        $this->assertSame('🙂', mb_substr($message->chunks[0], -1));
        $this->assertSame('শেষ', $message->chunks[1]);
    }

    public function test_reusing_a_logical_key_keeps_the_first_payload(): void
    {
        Queue::fake();
        $config = TelegramBotConfig::factory()->connected()->create();

        $first = (new QueueTelegramMessageAction)->handle($config, 555, 'first', 'telegram:test:stable');
        $second = (new QueueTelegramMessageAction)->handle($config, 555, 'second', 'telegram:test:stable');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(['first'], $second->chunks);
        Queue::assertPushed(SendTelegramOutboundMessageJob::class, 1);
    }

    public function test_a_legacy_global_key_is_reused_during_the_expand_phase(): void
    {
        Queue::fake();
        $config = TelegramBotConfig::factory()->connected()->create();
        $legacy = TelegramOutboundMessage::factory()->create([
            'telegram_bot_config_id' => $config->id,
            'webhook_generation' => $config->webhook_generation,
            'logical_key' => 'telegram:test:rotated',
            'chunks' => ['old'],
            'status' => TelegramOutboundMessage::SENT,
            'sent_at' => now(),
        ]);
        $config->update(['webhook_generation' => (string) Str::uuid()]);
        $legacyIndex = 'telegram_outbound_legacy_fence_test_unique';
        DB::statement("CREATE UNIQUE INDEX {$legacyIndex} ON telegram_outbound_messages (telegram_bot_config_id, logical_key)");

        try {
            $message = (new QueueTelegramMessageAction)->handle(
                $config->fresh(),
                555,
                'new',
                'telegram:test:rotated',
            );
        } finally {
            DB::statement("DROP INDEX IF EXISTS {$legacyIndex}");
        }

        $this->assertSame($legacy->id, $message->id);
        $this->assertSame($config->fresh()->webhook_generation, $message->webhook_generation);
        $this->assertSame(['new'], $message->chunks);
        $this->assertSame(TelegramOutboundMessage::PENDING, $message->status);
        Queue::assertPushed(SendTelegramOutboundMessageJob::class, 1);
    }

    public function test_an_in_flight_legacy_row_is_not_overwritten_during_expand(): void
    {
        Queue::fake();
        $config = TelegramBotConfig::factory()->connected()->create();
        $legacy = TelegramOutboundMessage::factory()->create([
            'telegram_bot_config_id' => $config->id,
            'webhook_generation' => $config->webhook_generation,
            'logical_key' => 'telegram:test:in-flight',
            'chunks' => ['old'],
            'status' => TelegramOutboundMessage::SENDING,
            'dispatch_claimed_at' => now(),
            'dispatch_lease_id' => (string) Str::uuid(),
        ]);
        $config->update(['webhook_generation' => (string) Str::uuid()]);
        $legacyIndex = 'telegram_outbound_legacy_in_flight_test_unique';
        DB::statement("CREATE UNIQUE INDEX {$legacyIndex} ON telegram_outbound_messages (telegram_bot_config_id, logical_key)");

        try {
            $message = (new QueueTelegramMessageAction)->handle(
                $config->fresh(),
                555,
                'new',
                'telegram:test:in-flight',
            );
        } finally {
            DB::statement("DROP INDEX IF EXISTS {$legacyIndex}");
        }

        $this->assertSame($legacy->id, $message->id);
        $this->assertSame(TelegramOutboundMessage::SENDING, $message->refresh()->status);
        $this->assertSame(['old'], $message->chunks);
        $this->assertSame(555, $message->chat_id);
        Queue::assertNothingPushed();
    }
}
