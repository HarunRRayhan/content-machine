<?php

namespace App\Actions\Telegram;

use App\Models\TelegramBotConfig;
use App\Support\Telegram\TelegramClientContract;

/**
 * Disables the bot (TelegramBotConfig::isConnected() becomes false)
 * without discarding webhook_secret/webhook_slug, so a later reconnect
 * doesn't change the workspace's webhook URL. Telegram's deleteWebhook is
 * called best-effort: a disconnect always succeeds locally even if
 * Telegram is briefly unreachable, since the user asked to turn this off
 * and shouldn't be blocked by Telegram's own API. Existing TelegramBotLink
 * rows are left untouched: reconnecting the same workspace's bot later
 * shouldn't force every already-linked member to re-link.
 */
class DisconnectTelegramBotAction
{
    public function __construct(
        private readonly TelegramClientContract $client,
    ) {}

    public function handle(TelegramBotConfig $config): void
    {
        if ($config->bot_token !== null) {
            $this->client->deleteWebhook($config->bot_token);
        }

        $config->update([
            'bot_token' => null,
            'bot_username' => null,
            'connected_at' => null,
        ]);
    }
}
