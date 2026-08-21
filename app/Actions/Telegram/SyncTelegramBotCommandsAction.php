<?php

namespace App\Actions\Telegram;

use App\Models\TelegramBotConfig;
use App\Support\Telegram\TelegramBotCommands;
use App\Support\Telegram\TelegramClientContract;
use Throwable;

/**
 * Re-registers Telegram's "/" command menu for every currently connected
 * bot at once. ConnectTelegramBotAction already does this for the one
 * workspace being connected right now; this is for the rest, whenever
 * TelegramBotCommands::LIST changes and already-connected bots need to
 * pick up the new entries without reconnecting. Called from a one-time
 * data migration on the deploy that changes the list (see
 * database/migrations/*_sync_commands_for_already_connected_telegram_bots.php
 * for the original), best-effort per bot so one bad token can't fail
 * the rest.
 */
class SyncTelegramBotCommandsAction
{
    public function __construct(
        private readonly TelegramClientContract $client,
    ) {}

    public function handle(): void
    {
        TelegramBotConfig::query()
            ->whereNotNull('bot_token')
            ->orderBy('id')
            ->each(function (TelegramBotConfig $config): void {
                try {
                    $this->client->setMyCommands((string) $config->bot_token, TelegramBotCommands::LIST);
                } catch (Throwable) {
                    // Best-effort: an unreachable Telegram or a bad token
                    // for one bot doesn't stop the rest of the sync.
                }
            });
    }
}
