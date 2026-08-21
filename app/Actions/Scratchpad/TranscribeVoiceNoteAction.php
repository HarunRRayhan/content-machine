<?php

namespace App\Actions\Scratchpad;

use App\Models\ScratchpadEntry;
use App\Models\TelegramBotConfig;
use App\Models\Transcription;
use App\Support\AiProviders\AiProviderCredentialResolver;
use App\Support\AiProviders\AiTranscriptionClientContract;
use App\Support\Telegram\TelegramClientContract;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToReadFile;

/**
 * Transcribes a voice note's audio, trying the workspace's openai-shaped AI
 * credentials in priority order (audio transcription has no
 * anthropic-shaped equivalent, so anthropic credentials in the chain are
 * skipped, not treated as failures). Never throws: every outcome, success
 * or exhausted fallback chain, is written to the Transcription row itself
 * (transcriptions.status/error_message), the same honest-degrade shape
 * this app already uses for link resolution and Telegram capture.
 *
 * A successful transcription backfills scratchpad_entries.language when
 * it wasn't already set, and — only for a Telegram-sourced entry — sends
 * the transcript back as a second bot message, since the "captured" reply
 * already went out before this job could possibly have finished.
 */
class TranscribeVoiceNoteAction
{
    public function __construct(
        private readonly AiTranscriptionClientContract $client,
        private readonly AiProviderCredentialResolver $resolver,
        private readonly TelegramClientContract $telegramClient,
    ) {}

    public function handle(Transcription $transcription): void
    {
        $transcription->update(['status' => 'processing']);

        $mediaAsset = $transcription->mediaAsset;
        $workspace = $mediaAsset->workspace;

        $credentials = $this->resolver->chain($workspace)->where('provider', 'openai');

        if ($credentials->isEmpty()) {
            $transcription->update([
                'status' => 'failed',
                'error_code' => 'no_provider_configured',
                'error_message' => 'No OpenAI-shaped AI provider is configured for this workspace.',
            ]);

            return;
        }

        try {
            $audioContents = Storage::disk($mediaAsset->disk)->get($mediaAsset->path);
        } catch (UnableToReadFile) {
            $audioContents = null;
        }

        if ($audioContents === null) {
            $transcription->update([
                'status' => 'failed',
                'error_code' => 'audio_missing',
                'error_message' => 'The audio file could not be read from storage.',
            ]);

            return;
        }

        $filename = $mediaAsset->original_filename ?? 'voice-note.ogg';
        $lastError = 'No provider attempt was made.';

        foreach ($credentials as $credential) {
            $result = $this->client->transcribe($credential, $audioContents, $filename, $mediaAsset->mime);

            if (! $result->successful) {
                $lastError = (string) $result->error;

                continue;
            }

            $transcription->update([
                'status' => 'done',
                'provider' => 'openai',
                'model' => 'whisper-1',
                'language' => $result->language,
                'text' => $result->text,
            ]);

            $entry = $transcription->scratchpadEntry;

            if ($entry !== null) {
                if ($entry->language === null && $result->language !== null) {
                    $entry->update(['language' => $result->language]);
                }

                $this->replyOnTelegram($entry, (string) $result->text);
            }

            return;
        }

        $transcription->update([
            'status' => 'failed',
            'error_code' => 'transcription_failed',
            'error_message' => $lastError,
        ]);
    }

    private function replyOnTelegram(ScratchpadEntry $entry, string $text): void
    {
        if ($entry->source !== 'telegram') {
            return;
        }

        $chatId = $entry->meta['telegram_chat_id'] ?? null;

        if (! is_int($chatId)) {
            return;
        }

        $config = TelegramBotConfig::query()->where('workspace_id', $entry->workspace_id)->first();

        if ($config === null || ! $config->isConnected()) {
            return;
        }

        $this->telegramClient->sendMessage((string) $config->bot_token, $chatId, "📝 Transcript: {$text}");
    }
}
