<?php

namespace App\Actions\Telegram;

use App\Models\TelegramBotConfig;

class ToggleTelegramAiChatAction
{
    public function handle(TelegramBotConfig $config): TelegramBotConfig
    {
        $config->update(['ai_chat_enabled' => ! $config->ai_chat_enabled]);

        return $config;
    }
}
