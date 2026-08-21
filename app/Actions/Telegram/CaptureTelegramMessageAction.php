<?php

namespace App\Actions\Telegram;

use App\Actions\Scratchpad\CaptureScratchpadLinkAction;
use App\Actions\Scratchpad\CaptureScratchpadPhotoAction;
use App\Actions\Scratchpad\CaptureScratchpadVoiceAction;
use App\Actions\Scratchpad\CaptureTextNoteAction;
use App\Data\Scratchpad\CaptureScratchpadLinkData;
use App\Data\Scratchpad\CaptureScratchpadPhotoData;
use App\Data\Scratchpad\CaptureScratchpadVoiceData;
use App\Data\Scratchpad\CaptureTextNoteData;
use App\Models\TelegramBotConfig;
use App\Models\Workspace;
use App\Support\Telegram\TelegramClientContract;
use Illuminate\Http\UploadedFile;

/**
 * Turns one Telegram `message` update into a Scratch Pad capture, reusing
 * the exact same Actions the dashboard's own capture forms use (with
 * source: 'telegram') rather than duplicating capture logic. A photo or
 * voice note is downloaded via Telegram's getFile-then-download dance
 * (TelegramClientContract::downloadFile) and wrapped as an UploadedFile so
 * CaptureScratchpadPhotoAction/CaptureScratchpadVoiceAction never need to
 * know the bytes didn't arrive as an HTTP multipart upload.
 *
 * Access control (is this sender linked to a workspace member?) and
 * command routing (/start, /link, /note, ...) both happen one layer up,
 * in HandleTelegramUpdateAction; by the time this Action runs, the sender
 * is already known-linked and the message is already known not to be a
 * command. Every message gets a real reply, never silence: capture
 * success or an unsupported-content notice.
 *
 * Text, links, photos, and voice notes are handled. A message with none
 * of those (document, video, sticker, forwarded audio file, ...) gets an
 * honest "not yet" reply rather than being silently dropped or
 * half-captured from just its caption.
 */
class CaptureTelegramMessageAction
{
    public function __construct(
        private readonly CaptureTextNoteAction $captureTextNoteAction,
        private readonly CaptureScratchpadLinkAction $captureScratchpadLinkAction,
        private readonly CaptureScratchpadPhotoAction $captureScratchpadPhotoAction,
        private readonly CaptureScratchpadVoiceAction $captureScratchpadVoiceAction,
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

        // Guaranteed non-null: ProcessTelegramUpdateJob only calls this
        // Action for a connected config. Narrowed here, once, so every
        // downloadFile()/sendMessage() call below gets a definite string
        // rather than repeating a null-check at each call site.
        $botToken = $config->bot_token;

        if ($botToken === null) {
            return;
        }

        $workspace = $config->workspace;
        $caption = $message['caption'] ?? null;
        $caption = is_string($caption) && trim($caption) !== '' ? trim($caption) : null;

        $photoSizes = $message['photo'] ?? null;

        if (is_array($photoSizes) && $photoSizes !== []) {
            $this->capturePhoto($config, $botToken, $workspace, $chatId, $photoSizes, $caption);

            return;
        }

        $voice = $message['voice'] ?? null;

        if (is_array($voice)) {
            $this->captureVoice($config, $botToken, $workspace, $chatId, $voice);

            return;
        }

        $text = $message['text'] ?? null;

        if (! is_string($text) || trim($text) === '') {
            $this->reply($config, $chatId, 'I can only capture text, links, photos, and voice notes right now.');

            return;
        }

        $text = trim($text);

        if (filter_var($text, FILTER_VALIDATE_URL) !== false) {
            $this->captureScratchpadLinkAction->handle($workspace, null, CaptureScratchpadLinkData::fromTelegram($text));
            $this->reply($config, $chatId, '🔗 Link captured.');

            return;
        }

        $this->captureTextNoteAction->handle($workspace, null, CaptureTextNoteData::fromTelegram($text));
        $this->reply($config, $chatId, 'Captured.');
    }

    /**
     * @param  array<int, array<string, mixed>>  $photoSizes  Telegram lists these smallest to largest
     */
    private function capturePhoto(TelegramBotConfig $config, string $botToken, Workspace $workspace, int $chatId, array $photoSizes, ?string $caption): void
    {
        $largest = end($photoSizes);
        $fileId = is_array($largest) ? ($largest['file_id'] ?? null) : null;

        if (! is_string($fileId)) {
            $this->reply($config, $chatId, "Couldn't read that photo.");

            return;
        }

        $download = $this->client->downloadFile($botToken, $fileId);

        if (! $download->successful) {
            $this->reply($config, $chatId, "Couldn't capture that photo: {$download->error}");

            return;
        }

        // Telegram's own `photo` field is always a compressed JPEG (a
        // full-quality original would arrive as a document instead), so
        // the declared type here is simply correct, not a guess. Images
        // are content-sniffed server-side regardless (ResolvesMediaAsset::
        // resolveMime()), so this declaration only affects the filename.
        $file = $this->toUploadedFile((string) $download->contents, 'telegram-photo.jpg', 'image/jpeg');

        try {
            $this->captureScratchpadPhotoAction->handle($workspace, null, CaptureScratchpadPhotoData::fromTelegram($file, $caption));
        } finally {
            @unlink($file->getRealPath());
        }

        $this->reply($config, $chatId, '📷 Photo captured.');
    }

    /**
     * @param  array<string, mixed>  $voice
     */
    private function captureVoice(TelegramBotConfig $config, string $botToken, Workspace $workspace, int $chatId, array $voice): void
    {
        $fileId = $voice['file_id'] ?? null;

        if (! is_string($fileId)) {
            $this->reply($config, $chatId, "Couldn't read that voice note.");

            return;
        }

        $download = $this->client->downloadFile($botToken, $fileId);

        if (! $download->successful) {
            $this->reply($config, $chatId, "Couldn't capture that voice note: {$download->error}");

            return;
        }

        $mimeType = $voice['mime_type'] ?? null;
        $mimeType = is_string($mimeType) && $mimeType !== '' ? $mimeType : 'audio/ogg';
        $file = $this->toUploadedFile((string) $download->contents, 'telegram-voice.'.$this->extensionForMime($mimeType), $mimeType);

        try {
            $this->captureScratchpadVoiceAction->handle($workspace, null, CaptureScratchpadVoiceData::fromTelegram($file, $chatId));
        } finally {
            @unlink($file->getRealPath());
        }

        $this->reply($config, $chatId, '🎙️ Voice note captured.');
    }

    private function toUploadedFile(string $contents, string $filename, string $mimeType): UploadedFile
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'telegram-media');
        file_put_contents($tempPath, $contents);

        // $test: true, since this file was never part of an HTTP multipart
        // upload — without it UploadedFile's own is_uploaded_file() check
        // would reject a perfectly good, locally-written temp file.
        return new UploadedFile($tempPath, $filename, $mimeType, null, true);
    }

    /**
     * Telegram voice notes are always audio/ogg (Opus) in practice, but
     * this covers the mime_type field honestly rather than hardcoding .ogg.
     */
    private function extensionForMime(string $mimeType): string
    {
        return match ($mimeType) {
            'audio/ogg' => 'ogg',
            'audio/mp4', 'audio/x-m4a' => 'm4a',
            'audio/mpeg' => 'mp3',
            'audio/wav', 'audio/x-wav' => 'wav',
            default => 'bin',
        };
    }

    private function reply(TelegramBotConfig $config, int $chatId, string $text): void
    {
        if ($config->bot_token !== null) {
            $this->client->sendMessage($config->bot_token, $chatId, $text);
        }
    }
}
