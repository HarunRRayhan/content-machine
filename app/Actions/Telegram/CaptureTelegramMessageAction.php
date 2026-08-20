<?php

namespace App\Actions\Telegram;

use App\Actions\Scratchpad\CaptureScratchpadLinkAction;
use App\Actions\Scratchpad\CaptureTextNoteAction;
use App\Data\Scratchpad\CaptureScratchpadLinkData;
use App\Data\Scratchpad\CaptureTextNoteData;
use App\Models\TelegramBotConfig;
use App\Support\Telegram\TelegramClientContract;

/**
 * Turns one Telegram `message` update into a Scratch Pad capture, reusing
 * the exact same Actions the dashboard's own text-note and link forms use
 * (with source: 'telegram') rather than duplicating capture logic.
 *
 * Access control: whoever messages the bot first is bound as the only
 * sender that gets captured (TelegramBotConfig::linked_telegram_user_id),
 * see the migration's docblock for why. Every message gets a real reply,
 * never silence: capture success, an unsupported-content notice, or the
 * "this bot is private" rejection.
 *
 * Only plain text is handled today. A message with no text (photo, voice,
 * document, sticker, ...) gets an honest "not yet" reply rather than being
 * silently dropped or half-captured from just its caption.
 */
class CaptureTelegramMessageAction
{
    public function __construct(
        private readonly CaptureTextNoteAction $captureTextNoteAction,
        private readonly CaptureScratchpadLinkAction $captureScratchpadLinkAction,
        private readonly TelegramClientContract $client,
    ) {}

    /**
     * @param  array<string, mixed>  $update  the raw Telegram Update payload
     */
    public function handle(TelegramBotConfig $config, array $update): void
    {
        $message = $update['message'] ?? null;

        if (! is_array($message)) {
            return;
        }

        $chatId = $message['chat']['id'] ?? null;
        $fromUserId = $message['from']['id'] ?? null;

        if (! is_int($chatId) || ! is_int($fromUserId)) {
            return;
        }

        if ($config->linked_telegram_user_id === null) {
            $config->update(['linked_telegram_user_id' => $fromUserId]);
        } elseif ($config->linked_telegram_user_id !== $fromUserId) {
            $this->reply($config, $chatId, 'This bot is private.');

            return;
        }

        $text = $message['text'] ?? null;

        if (! is_string($text) || trim($text) === '') {
            $this->reply($config, $chatId, 'I can only capture text and links right now. Photos and voice notes are coming soon.');

            return;
        }

        $text = trim($text);
        $workspace = $config->workspace;

        if (filter_var($text, FILTER_VALIDATE_URL) !== false) {
            $this->captureScratchpadLinkAction->handle($workspace, null, CaptureScratchpadLinkData::fromTelegram($text));
            $this->reply($config, $chatId, '🔗 Link captured.');

            return;
        }

        $this->captureTextNoteAction->handle($workspace, null, CaptureTextNoteData::fromTelegram($text));
        $this->reply($config, $chatId, 'Captured.');
    }

    private function reply(TelegramBotConfig $config, int $chatId, string $text): void
    {
        if ($config->bot_token !== null) {
            $this->client->sendMessage($config->bot_token, $chatId, $text);
        }
    }
}
