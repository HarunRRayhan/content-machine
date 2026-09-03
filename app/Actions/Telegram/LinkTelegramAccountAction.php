<?php

namespace App\Actions\Telegram;

use App\Models\TelegramBotConfig;
use App\Models\TelegramBotLink;
use App\Models\TelegramLinkCode;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Redeems a `/link CODE` message into a real TelegramBotLink, proving
 * which app user this Telegram sender is. Called from
 * HandleTelegramUpdateAction, never directly from a controller: the code
 * only ever travels from the dashboard (GenerateTelegramLinkCodeAction)
 * to Telegram, never the other way.
 *
 * @throws RuntimeException with a message safe to send back to the sender as-is
 */
class LinkTelegramAccountAction
{
    public function handle(TelegramBotConfig $config, string $code, int $telegramUserId, ?string $telegramUsername): TelegramBotLink
    {
        return DB::transaction(function () use ($config, $code, $telegramUserId, $telegramUsername): TelegramBotLink {
            // Different valid codes can target the same Telegram account, so
            // lock the bot row as well as the individual code row.
            $lockedConfig = TelegramBotConfig::query()
                ->whereKey($config->id)
                ->lockForUpdate()
                ->firstOrFail();

            $linkCode = TelegramLinkCode::query()
                ->where('telegram_bot_config_id', $lockedConfig->id)
                ->where('code', strtoupper(trim($code)))
                ->lockForUpdate()
                ->first();

            if ($linkCode === null) {
                throw new RuntimeException("I don't recognize that code. Get a fresh one from Settings → Telegram in the dashboard.");
            }

            if (! $linkCode->isUsable()) {
                throw new RuntimeException('That code has expired or was already used. Get a fresh one from Settings → Telegram in the dashboard.');
            }

            $conflict = TelegramBotLink::query()
                ->where('telegram_bot_config_id', $lockedConfig->id)
                ->where('telegram_user_id', $telegramUserId)
                ->where('user_id', '!=', $linkCode->user_id)
                ->exists();

            if ($conflict) {
                throw new RuntimeException('This Telegram account is already linked to a different member of this workspace.');
            }

            $linkCode->forceFill(['consumed_at' => now()])->save();

            return TelegramBotLink::updateOrCreate(
                ['telegram_bot_config_id' => $lockedConfig->id, 'user_id' => $linkCode->user_id],
                ['telegram_user_id' => $telegramUserId, 'telegram_username' => $telegramUsername, 'linked_at' => now()],
            );
        });
    }
}
