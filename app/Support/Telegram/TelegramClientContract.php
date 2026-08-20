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

    /**
     * Resolves a file_id (from a message's photo/voice/etc.) to its raw
     * bytes, via Telegram's own two-step getFile-then-download dance — the
     * caller never sees that mechanic, only the result. Fails honestly
     * (rather than throwing) when Telegram can't find the file or, per the
     * Bot API's 20MB cap, refuses to hand it over for being too big.
     */
    public function downloadFile(string $botToken, string $fileId): TelegramFileDownloadResult;
}
