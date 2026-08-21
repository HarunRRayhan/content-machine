<?php

namespace App\Actions\Telegram;

use App\Models\TelegramBotConfig;
use App\Support\AiProviders\AiProviderCredentialResolver;
use RuntimeException;

/**
 * Turning off always succeeds. Turning on requires a working AI provider
 * to already exist for the workspace, refused rather than silently
 * flipping the flag with nothing behind it — GenerateTelegramChatReplyAction
 * would just fall back to capture every time anyway, which would read as
 * the toggle "not working" rather than as the honest reason it is.
 *
 * @throws RuntimeException with a message safe to show in the dashboard as-is
 */
class ToggleTelegramAiChatAction
{
    public function __construct(
        private readonly AiProviderCredentialResolver $resolver,
    ) {}

    public function handle(TelegramBotConfig $config): TelegramBotConfig
    {
        $turningOn = ! $config->ai_chat_enabled;

        if ($turningOn && $this->resolver->default($config->workspace) === null) {
            throw new RuntimeException('First configure your AI providers.');
        }

        $config->update(['ai_chat_enabled' => $turningOn]);

        return $config;
    }
}
