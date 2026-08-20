<?php

namespace App\Support\Telegram;

interface TelegramClientContract
{
    /**
     * Calls Telegram's getMe, the cheapest way to confirm a bot token
     * actually authenticates and to learn the bot's own @username.
     */
    public function getMe(string $botToken): TelegramGetMeResult;
}
