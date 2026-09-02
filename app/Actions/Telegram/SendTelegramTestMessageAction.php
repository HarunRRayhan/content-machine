<?php

namespace App\Actions\Telegram;

use App\Models\TelegramBotConfig;
use App\Models\TelegramBotLink;
use App\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Proves round-trip delivery for the currently logged-in user: sends a
 * message to *their own* linked chat, not to whichever Telegram account
 * happened to message the bot first (there's no such singular sender any
 * more, see TelegramBotLink). Requires the caller to already be linked.
 *
 * @throws RuntimeException with a message safe to show in the dashboard as-is
 */
class SendTelegramTestMessageAction
{
    public function handle(TelegramBotConfig $config, User $user): void
    {
        if ($config->bot_token === null) {
            throw new RuntimeException('Connect the bot before sending a test message.');
        }

        $link = TelegramBotLink::query()
            ->where('telegram_bot_config_id', $config->id)
            ->where('user_id', $user->id)
            ->first();

        if ($link === null) {
            throw new RuntimeException('Link your own Telegram account first, then send a test message.');
        }

        (new QueueTelegramMessageAction)->handle(
            $config,
            $link->telegram_user_id,
            "✅ Test message from Content Machine. If you're reading this, delivery works.",
            'telegram:test:'.$config->id.':'.Str::uuid(),
            $config->webhook_generation,
        );
    }
}
