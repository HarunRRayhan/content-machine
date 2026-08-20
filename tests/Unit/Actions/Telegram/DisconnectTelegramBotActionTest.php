<?php

namespace Tests\Unit\Actions\Telegram;

use App\Actions\Telegram\DisconnectTelegramBotAction;
use App\Models\TelegramBotConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DisconnectTelegramBotActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_clears_the_token_and_username_but_keeps_the_webhook_identity()
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $secret = $config->webhook_secret;
        $slug = $config->webhook_slug;

        (new DisconnectTelegramBotAction)->handle($config);
        $fresh = $config->fresh();

        $this->assertFalse($fresh->isConnected());
        $this->assertNull($fresh->bot_username);
        $this->assertNull($fresh->connected_at);
        $this->assertSame($secret, $fresh->webhook_secret);
        $this->assertSame($slug, $fresh->webhook_slug);
    }
}
