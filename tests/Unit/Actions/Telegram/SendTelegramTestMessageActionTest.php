<?php

namespace Tests\Unit\Actions\Telegram;

use App\Actions\Telegram\SendTelegramTestMessageAction;
use App\Models\TelegramBotConfig;
use App\Models\TelegramBotLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\Telegram\FakeTelegramClient;
use Tests\TestCase;

class SendTelegramTestMessageActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_to_the_users_own_linked_chat()
    {
        $config = TelegramBotConfig::factory()->connected()->create(['bot_token' => '123:tok']);
        $user = User::factory()->create();
        TelegramBotLink::factory()->create(['telegram_bot_config_id' => $config->id, 'user_id' => $user->id, 'telegram_user_id' => 555]);
        $client = new FakeTelegramClient;

        (new SendTelegramTestMessageAction($client))->handle($config, $user);

        $this->assertCount(1, $client->sentMessages);
        $this->assertSame('123:tok', $client->sentMessages[0]['botToken']);
        $this->assertSame(555, $client->sentMessages[0]['chatId']);
    }

    public function test_an_unlinked_user_gets_a_clear_error_and_nothing_is_sent()
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $user = User::factory()->create();
        $client = new FakeTelegramClient;

        $this->expectException(RuntimeException::class);

        try {
            (new SendTelegramTestMessageAction($client))->handle($config, $user);
        } finally {
            $this->assertSame([], $client->sentMessages);
        }
    }
}
