<?php

namespace Tests\Unit\Actions\Telegram;

use App\Actions\Telegram\ToggleTelegramAiChatAction;
use App\Models\TelegramBotConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToggleTelegramAiChatActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_flips_ai_chat_enabled_each_call()
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $this->assertFalse($config->ai_chat_enabled);

        (new ToggleTelegramAiChatAction)->handle($config);
        $this->assertTrue($config->fresh()->ai_chat_enabled);

        (new ToggleTelegramAiChatAction)->handle($config->fresh());
        $this->assertFalse($config->fresh()->ai_chat_enabled);
    }
}
