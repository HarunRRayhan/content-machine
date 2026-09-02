<?php

namespace App\Support\Telegram;

use App\Models\TelegramBotConfig;

final class TelegramUpdateKey
{
    /**
     * Build one stable key for every update accepted by one webhook token
     * generation. A token rotation must produce a different key even when
     * Telegram reuses an update id for the replacement bot.
     *
     * @param  array<string, mixed>  $update
     */
    public static function from(TelegramBotConfig $config, array $update): ?string
    {
        $generation = $config->webhook_generation;
        $updateId = $update['update_id'] ?? null;

        if (! is_string($generation) || $generation === '') {
            return null;
        }

        if (! is_int($updateId) && ! (is_string($updateId) && ctype_digit($updateId))) {
            return null;
        }

        return hash('sha256', $config->id.':'.$generation.':'.(int) $updateId);
    }
}
