<?php

namespace App\Actions\Telegram;

use App\Models\TelegramBotConfig;

/**
 * Disables the bot (TelegramBotConfig::isConnected() becomes false)
 * without discarding webhook_secret/webhook_slug, so a later reconnect
 * doesn't change the workspace's webhook URL.
 */
class DisconnectTelegramBotAction
{
    public function handle(TelegramBotConfig $config): void
    {
        $config->update([
            'bot_token' => null,
            'bot_username' => null,
            'connected_at' => null,
        ]);
    }
}
