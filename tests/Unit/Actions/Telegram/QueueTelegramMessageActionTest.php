<?php

namespace Tests\Unit\Actions\Telegram;

use App\Actions\Telegram\QueueTelegramMessageAction;
use App\Jobs\SendTelegramOutboundMessageJob;
use App\Models\TelegramBotConfig;
use App\Models\TelegramOutboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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
}
