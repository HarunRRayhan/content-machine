<?php

namespace App\Support\Telegram;

interface TelegramClientContract
{
    /**
     * Calls Telegram's getMe, the cheapest way to confirm a bot token
     * actually authenticates and to learn the bot's own @username.
     */
    public function getMe(string $botToken): TelegramGetMeResult;

    /**
     * Registers $url (with $secretToken as the value Telegram will echo
     * back in X-Telegram-Bot-Api-Secret-Token on every delivery) as this
     * bot's webhook, scoped to message updates only.
     */
    public function setWebhook(string $botToken, string $url, string $secretToken): TelegramApiResult;

    /**
     * Unregisters this bot's webhook. Called best-effort on disconnect;
     * the caller doesn't fail a disconnect just because this failed.
     */
    public function deleteWebhook(string $botToken): TelegramApiResult;

    public function sendMessage(string $botToken, int $chatId, string $text): TelegramApiResult;
}
