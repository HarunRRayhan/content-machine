<?php

namespace Tests\Unit\Actions\Telegram;

use App\Actions\Telegram\DisconnectTelegramBotAction;
use App\Models\TelegramBotConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Telegram\FakeTelegramClient;
use Tests\TestCase;

class DisconnectTelegramBotActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_clears_the_token_and_username_but_keeps_the_webhook_identity()
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $secret = $config->webhook_secret;
        $slug = $config->webhook_slug;

        (new DisconnectTelegramBotAction(new FakeTelegramClient))->handle($config);
        $fresh = $config->fresh();

        $this->assertFalse($fresh->isConnected());
        $this->assertNull($fresh->bot_username);
        $this->assertNull($fresh->connected_at);
        $this->assertSame($secret, $fresh->webhook_secret);
        $this->assertSame($slug, $fresh->webhook_slug);
    }

    public function test_it_tells_telegram_to_remove_the_webhook_using_the_still_present_token()
    {
        $config = TelegramBotConfig::factory()->connected()->create(['bot_token' => '123:the-token']);
        $client = new FakeTelegramClient;

        (new DisconnectTelegramBotAction($client))->handle($config);

        $this->assertSame(['123:the-token'], $client->deleteWebhookCalledWith);
    }

    public function test_it_does_not_call_telegram_when_already_disconnected()
    {
        $config = TelegramBotConfig::factory()->create();
        $client = new FakeTelegramClient;

        (new DisconnectTelegramBotAction($client))->handle($config);

        $this->assertSame([], $client->deleteWebhookCalledWith);
    }
}
