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
     * Registers the bot's command menu, the "/" list Telegram shows in its
     * own client UI. Best-effort: called once on connect, a failure here
     * doesn't block the connection (the commands still work as plain
     * messages, they're just not suggested by Telegram's UI).
     *
     * @param  array<int, array{command: string, description: string}>  $commands
     */
    public function setMyCommands(string $botToken, array $commands): TelegramApiResult;

    /**
     * Resolves a file_id (from a message's photo/voice/etc.) to its raw
     * bytes, via Telegram's own two-step getFile-then-download dance — the
     * caller never sees that mechanic, only the result. Fails honestly
     * (rather than throwing) when Telegram can't find the file or, per the
     * Bot API's 20MB cap, refuses to hand it over for being too big.
     */
    public function downloadFile(string $botToken, string $fileId): TelegramFileDownloadResult;

    /**
     * Shows Telegram's own "..." typing indicator in the chat. It clears
     * itself after a few seconds or as soon as a message actually arrives
     * from this bot, whichever comes first, so a caller expecting a slow
     * reply (an AI completion call) resends this before each blocking step
     * rather than relying on a single call to cover the whole wait.
     * Best-effort: a failure here never blocks the real reply.
     */
    public function sendChatAction(string $botToken, int $chatId, string $action): TelegramApiResult;

    /**
     * Sets (replacing any existing one) a single emoji reaction on
     * $messageId, the fastest visible acknowledgement Telegram offers that
     * a message actually arrived, well before any reply text exists.
     * Best-effort: a failure here never blocks the real reply.
     */
    public function setMessageReaction(string $botToken, int $chatId, int $messageId, string $emoji): TelegramApiResult;
}
