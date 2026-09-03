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
use App\Models\ScratchpadEntry;
use App\Models\TelegramBotConfig;
use App\Models\Workspace;
use App\Support\LinkResolution\PublicUrlGuard;
use App\Support\Telegram\TelegramClientContract;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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
 * Text, links, photos, voice notes, and audio files are handled. A message with none
 * of those (document, video, sticker, or other unsupported media) gets an
 * honest "not yet" reply rather than being silently dropped or
 * half-captured from just its caption.
 */
class CaptureTelegramMessageAction
{
    private ?string $replyContextKey = null;

    public function __construct(
        private readonly CaptureTextNoteAction $captureTextNoteAction,
        private readonly CaptureScratchpadLinkAction $captureScratchpadLinkAction,
        private readonly CaptureScratchpadPhotoAction $captureScratchpadPhotoAction,
        private readonly CaptureScratchpadVoiceAction $captureScratchpadVoiceAction,
        private readonly TelegramClientContract $client,
        private readonly ?PublicUrlGuard $urlGuard = null,
    ) {}

    /**
     * @param  array<string, mixed>  $update  the raw Telegram Update payload
     */
    public function handle(
        TelegramBotConfig $config,
        array $update,
        bool $reply = true,
        bool $queueEnrichment = true,
        ?string $telegramUpdateKey = null,
    ): ?ScratchpadEntry {
        $message = $update['message'] ?? null;

        if (! is_array($message)) {
            return null;
        }

        $chat = $message['chat'] ?? null;
        if (! is_array($chat) || ($chat['type'] ?? null) !== 'private') {
            return null;
        }

        $chatId = $chat['id'] ?? null;
        $fromUserId = $message['from']['id'] ?? null;

        if (! is_int($chatId) || ! is_int($fromUserId)) {
            return null;
        }

        $this->replyContextKey = $telegramUpdateKey
            ?? hash('sha256', $config->id.':'.serialize($update));

        if ($telegramUpdateKey !== null) {
            $lock = Cache::lock('telegram:capture:'.$telegramUpdateKey, 120);
            $lock->block(30);

            try {
                return $this->captureCurrentGeneration(
                    $config,
                    $chatId,
                    $update,
                    $reply,
                    $queueEnrichment,
                    $telegramUpdateKey,
                );
            } finally {
                $lock->release();
            }
        }

        return $this->captureCurrentGeneration(
            $config,
            $chatId,
            $update,
            $reply,
            $queueEnrichment,
            null,
        );
    }

    /**
     * Keep the bot identity locked through the capture and its Telegram
     * acknowledgement. Rotation must not commit between the generation check
     * and persistence of a source capture.
     *
     * @param  array<string, mixed>  $update
     */
    private function captureCurrentGeneration(
        TelegramBotConfig $config,
        int $chatId,
        array $update,
        bool $reply,
        bool $queueEnrichment,
        ?string $telegramUpdateKey,
    ): ?ScratchpadEntry {
        return DB::transaction(function () use ($config, $chatId, $update, $reply, $queueEnrichment, $telegramUpdateKey): ?ScratchpadEntry {
            $configReference = TelegramBotConfig::query()
                ->whereKey($config->id)
                ->first(['workspace_id']);

            if ($configReference === null) {
                return null;
            }

            Workspace::query()
                ->whereKey($configReference->workspace_id)
                ->lockForUpdate()
                ->first();

            $lockedConfig = TelegramBotConfig::query()
                ->whereKey($config->id)
                ->lockForUpdate()
                ->first();

            if ($lockedConfig === null
                || ! $lockedConfig->isConnected()
                || ($config->webhook_generation !== null
                    && $lockedConfig->webhook_generation !== $config->webhook_generation)
                || $lockedConfig->bot_token === null
            ) {
                return null;
            }

            return $this->captureUnlocked(
                $lockedConfig,
                $lockedConfig->bot_token,
                $lockedConfig->workspace,
                $chatId,
                $update,
                $reply,
                $queueEnrichment,
                $telegramUpdateKey,
            );
        });
    }

    /**
     * @param  array<string, mixed>  $update
     */
    private function captureUnlocked(
        TelegramBotConfig $config,
        string $botToken,
        Workspace $workspace,
        int $chatId,
        array $update,
        bool $reply,
        bool $queueEnrichment,
        ?string $telegramUpdateKey,
    ): ?ScratchpadEntry {
        $message = $update['message'] ?? null;
        if (! is_array($message)) {
            return null;
        }

        $existing = $telegramUpdateKey === null
            ? null
            : ScratchpadEntry::query()
                ->where('telegram_update_key', $telegramUpdateKey)
                ->first();

        if ($existing !== null) {
            if ($reply) {
                $this->reply($config, $chatId, match ($existing->kind) {
                    'link' => '🔗 Link captured.',
                    'photo' => '📷 Photo captured.',
                    'voice' => '🎙️ Voice note captured.',
                    default => 'Captured.',
                });
            }

            return $existing;
        }

        $caption = $message['caption'] ?? null;
        $caption = is_string($caption) && trim($caption) !== '' ? trim($caption) : null;

        $photoSizes = $message['photo'] ?? null;

        if (is_array($photoSizes) && $photoSizes !== []) {
            return $this->capturePhoto($config, $botToken, $workspace, $chatId, $photoSizes, $caption, $reply, $telegramUpdateKey);

        }

        $voice = $message['voice'] ?? null;

        if (is_array($voice)) {
            return $this->captureVoice($config, $botToken, $workspace, $chatId, $voice, $caption, $reply, $queueEnrichment, $telegramUpdateKey);

        }

        $audio = $message['audio'] ?? null;

        if (is_array($audio)) {
            return $this->captureVoice($config, $botToken, $workspace, $chatId, $audio, $caption, $reply, $queueEnrichment, $telegramUpdateKey);

        }

        $text = $message['text'] ?? null;

        if (! is_string($text) || trim($text) === '') {
            if ($reply) {
                $this->reply($config, $chatId, 'I can only capture text, links, photos, voice notes, and audio files right now.');
            }

            return null;
        }

        $text = trim($text);

        if (filter_var($text, FILTER_VALIDATE_URL) !== false) {
            if (! $this->guard()->isSafe($text)) {
                if ($reply) {
                    $this->reply($config, $chatId, 'I can only resolve public http(s) links.');
                }

                return null;
            }

            $entry = $this->captureScratchpadLinkAction->handle(
                $workspace,
                null,
                CaptureScratchpadLinkData::fromTelegram($text),
                $queueEnrichment,
                $telegramUpdateKey,
                $config->webhook_generation,
            );
            if ($reply) {
                $this->reply($config, $chatId, '🔗 Link captured.');
            }

            return $entry;
        }

        $entry = $this->captureTextNoteAction->handle(
            $workspace,
            null,
            CaptureTextNoteData::fromTelegram($text),
            $telegramUpdateKey,
            $config->webhook_generation,
        );
        if ($reply) {
            $this->reply($config, $chatId, 'Captured.');
        }

        return $entry;
    }

    /**
     * @param  array<int, array<string, mixed>>  $photoSizes  Telegram lists these smallest to largest
     */
    private function capturePhoto(
        TelegramBotConfig $config,
        string $botToken,
        Workspace $workspace,
        int $chatId,
        array $photoSizes,
        ?string $caption,
        bool $reply,
        ?string $telegramUpdateKey,
    ): ?ScratchpadEntry {
        $largest = end($photoSizes);
        $fileId = is_array($largest) ? ($largest['file_id'] ?? null) : null;

        if (! is_string($fileId)) {
            if ($reply) {
                $this->reply($config, $chatId, "Couldn't read that photo.");
            }

            return null;
        }

        $download = $this->client->downloadFile($botToken, $fileId);

        if (! $download->successful) {
            if ($reply) {
                $this->reply($config, $chatId, "Couldn't capture that photo: {$download->error}");
            }

            return null;
        }

        // Telegram's own `photo` field is always a compressed JPEG (a
        // full-quality original would arrive as a document instead), so
        // the declared type here is simply correct, not a guess. Images
        // are content-sniffed server-side regardless (ResolvesMediaAsset::
        // resolveMime()), so this declaration only affects the filename.
        $file = $this->toUploadedFile((string) $download->contents, 'telegram-photo.jpg', 'image/jpeg');

        try {
            $entry = $this->captureScratchpadPhotoAction->handle(
                $workspace,
                null,
                CaptureScratchpadPhotoData::fromTelegram($file, $caption),
                $telegramUpdateKey,
                $config->webhook_generation,
            );
        } finally {
            @unlink($file->getRealPath());
        }

        if ($reply) {
            $this->reply($config, $chatId, '📷 Photo captured.');
        }

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $voice
     */
    private function captureVoice(
        TelegramBotConfig $config,
        string $botToken,
        Workspace $workspace,
        int $chatId,
        array $voice,
        ?string $caption,
        bool $reply,
        bool $queueEnrichment,
        ?string $telegramUpdateKey,
    ): ?ScratchpadEntry {
        $fileId = $voice['file_id'] ?? null;

        if (! is_string($fileId)) {
            if ($reply) {
                $this->reply($config, $chatId, "Couldn't read that voice note.");
            }

            return null;
        }

        $download = $this->client->downloadFile($botToken, $fileId);

        if (! $download->successful) {
            if ($reply) {
                $this->reply($config, $chatId, "Couldn't capture that voice note: {$download->error}");
            }

            return null;
        }

        $mimeType = $voice['mime_type'] ?? null;
        $mimeType = is_string($mimeType) && $mimeType !== '' ? $mimeType : 'audio/ogg';
        $originalName = $voice['file_name'] ?? null;
        $originalName = is_string($originalName) && $originalName !== ''
            ? $originalName
            : 'telegram-voice.'.$this->extensionForMime($mimeType);
        $file = $this->toUploadedFile((string) $download->contents, $originalName, $mimeType);

        try {
            $entry = $this->captureScratchpadVoiceAction->handle(
                $workspace,
                null,
                CaptureScratchpadVoiceData::fromTelegram($file, $chatId, $caption),
                $queueEnrichment,
                $telegramUpdateKey,
                $config->webhook_generation,
            );
        } finally {
            @unlink($file->getRealPath());
        }

        if ($reply) {
            $this->reply($config, $chatId, '🎙️ Voice note captured.');
        }

        return $entry;
    }

    private function toUploadedFile(string $contents, string $filename, string $mimeType): UploadedFile
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'telegram-media');

        if ($tempPath === false) {
            throw new RuntimeException('Could not create a temporary file for the Telegram media.');
        }

        if (file_put_contents($tempPath, $contents) === false) {
            @unlink($tempPath);

            throw new RuntimeException('Could not write the Telegram media to temporary storage.');
        }

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
            (new QueueTelegramMessageAction)->handle(
                $config,
                $chatId,
                $text,
                'telegram:update:'.($this->replyContextKey ?? hash('sha256', $config->id.':'.$chatId)).':reply:capture',
                $config->webhook_generation,
            );
        }
    }

    private function guard(): PublicUrlGuard
    {
        return $this->urlGuard ?? app(PublicUrlGuard::class);
    }
}
