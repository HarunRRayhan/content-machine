<?php

namespace App\Actions\Telegram;

use App\Models\TelegramBotConfig;
use App\Models\TelegramLinkCode;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Issues a fresh, single-use code for one team member to link their own
 * Telegram account to the workspace's bot (see LinkTelegramAccountAction).
 * Any of that user's earlier still-usable codes for this bot are deleted
 * first, so re-requesting from the dashboard doesn't leave several valid
 * codes floating around and only the newest one shown on screen actually
 * works.
 */
class GenerateTelegramLinkCodeAction
{
    private const TTL_MINUTES = 15;

    public function handle(TelegramBotConfig $config, User $user): TelegramLinkCode
    {
        TelegramLinkCode::query()
            ->where('telegram_bot_config_id', $config->id)
            ->where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->delete();

        do {
            $code = Str::upper(Str::random(8));
        } while (TelegramLinkCode::query()->where('code', $code)->exists());

        return TelegramLinkCode::create([
            'telegram_bot_config_id' => $config->id,
            'user_id' => $user->id,
            'code' => $code,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);
    }
}
