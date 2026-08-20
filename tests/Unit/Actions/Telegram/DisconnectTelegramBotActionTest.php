<?php

namespace Tests\Unit\Actions\Telegram;

use App\Actions\Telegram\DisconnectTelegramBotAction;
use App\Models\TelegramBotConfig;
use App\Support\Telegram\TelegramApiResult;
use App\Support\Telegram\TelegramClientContract;
use App\Support\Telegram\TelegramGetMeResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DisconnectTelegramBotActionTest extends TestCase
{
    use RefreshDatabase;

    private function fakeClient(): TelegramClientContract
    {
        return new class implements TelegramClientContract
        {
            public array $deleteWebhookCalledWith = [];

            public function getMe(string $botToken): TelegramGetMeResult
            {
                return TelegramGetMeResult::success('irrelevant');
            }

            public function setWebhook(string $botToken, string $url, string $secretToken): TelegramApiResult
            {
                return TelegramApiResult::success();
            }

            public function deleteWebhook(string $botToken): TelegramApiResult
            {
                $this->deleteWebhookCalledWith[] = $botToken;

                return TelegramApiResult::success();
            }

            public function sendMessage(string $botToken, int $chatId, string $text): TelegramApiResult
            {
                return TelegramApiResult::success();
            }
        };
    }

    public function test_it_clears_the_token_username_and_sender_lock_but_keeps_the_webhook_identity()
    {
        $config = TelegramBotConfig::factory()->connected()->create(['linked_telegram_user_id' => 555]);
        $secret = $config->webhook_secret;
        $slug = $config->webhook_slug;

        (new DisconnectTelegramBotAction($this->fakeClient()))->handle($config);
        $fresh = $config->fresh();

        $this->assertFalse($fresh->isConnected());
        $this->assertNull($fresh->bot_username);
        $this->assertNull($fresh->connected_at);
        $this->assertNull($fresh->linked_telegram_user_id);
        $this->assertSame($secret, $fresh->webhook_secret);
        $this->assertSame($slug, $fresh->webhook_slug);
    }

    public function test_it_tells_telegram_to_remove_the_webhook_using_the_still_present_token()
    {
        $config = TelegramBotConfig::factory()->connected()->create(['bot_token' => '123:the-token']);
        $client = $this->fakeClient();

        (new DisconnectTelegramBotAction($client))->handle($config);

        $this->assertSame(['123:the-token'], $client->deleteWebhookCalledWith);
    }

    public function test_it_does_not_call_telegram_when_already_disconnected()
    {
        $config = TelegramBotConfig::factory()->create();
        $client = $this->fakeClient();

        (new DisconnectTelegramBotAction($client))->handle($config);

        $this->assertSame([], $client->deleteWebhookCalledWith);
    }
}
