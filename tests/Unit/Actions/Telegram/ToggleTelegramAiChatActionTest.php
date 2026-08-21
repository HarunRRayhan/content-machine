<?php

namespace Tests\Unit\Actions\Telegram;

use App\Actions\Telegram\ToggleTelegramAiChatAction;
use App\Models\AiProviderCredential;
use App\Models\TelegramBotConfig;
use App\Support\AiProviders\AiProviderCredentialResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ToggleTelegramAiChatActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_flips_ai_chat_enabled_each_call_when_a_provider_is_configured()
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        AiProviderCredential::factory()->withModel()->create(['workspace_id' => $config->workspace_id]);
        $action = new ToggleTelegramAiChatAction(new AiProviderCredentialResolver);
        $this->assertFalse($config->ai_chat_enabled);

        $action->handle($config);
        $this->assertTrue($config->fresh()->ai_chat_enabled);

        $action->handle($config->fresh());
        $this->assertFalse($config->fresh()->ai_chat_enabled);
    }

    public function test_turning_on_without_a_provider_throws_and_leaves_it_off()
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $action = new ToggleTelegramAiChatAction(new AiProviderCredentialResolver);

        try {
            $action->handle($config);
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertSame('First configure your AI providers.', $e->getMessage());
        }

        $this->assertFalse($config->fresh()->ai_chat_enabled);
    }

    public function test_turning_off_never_requires_a_provider()
    {
        $config = TelegramBotConfig::factory()->connected()->aiChatEnabled()->create();
        $action = new ToggleTelegramAiChatAction(new AiProviderCredentialResolver);

        $action->handle($config);

        $this->assertFalse($config->fresh()->ai_chat_enabled);
    }
}
