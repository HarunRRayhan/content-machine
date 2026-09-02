<?php

namespace Tests\Unit\Actions\Telegram;

use App\Actions\Telegram\SendTelegramTestMessageAction;
use App\Models\TelegramBotConfig;
use App\Models\TelegramBotLink;
use App\Models\TelegramOutboundMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class SendTelegramTestMessageActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_to_the_users_own_linked_chat()
    {
        Queue::fake();
        $config = TelegramBotConfig::factory()->connected()->create(['bot_token' => '123:tok']);
        $user = User::factory()->create();
        TelegramBotLink::factory()->create(['telegram_bot_config_id' => $config->id, 'user_id' => $user->id, 'telegram_user_id' => 555]);

        (new SendTelegramTestMessageAction)->handle($config, $user);

        $message = TelegramOutboundMessage::query()->sole();
        $this->assertSame('123:tok', $message->telegramBotConfig?->bot_token);
        $this->assertSame(555, $message->chat_id);
    }

    public function test_an_unlinked_user_gets_a_clear_error_and_nothing_is_sent()
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $user = User::factory()->create();

        $this->expectException(RuntimeException::class);

        try {
            (new SendTelegramTestMessageAction)->handle($config, $user);
        } finally {
            $this->assertSame(0, TelegramOutboundMessage::count());
        }
    }
}
