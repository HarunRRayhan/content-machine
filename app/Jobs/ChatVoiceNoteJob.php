<?php

namespace App\Jobs;

use App\Actions\Telegram\HandleTelegramUpdateAction;
use App\Models\TelegramBotConfig;
use App\Models\Transcription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Runs a Telegram voice note's transcript through the same AI-chat pipeline
 * a typed message uses. Dispatched once, when the transcription flips to done.
 */
class ChatVoiceNoteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Chat can save or delete notes: never retry, and claim the run so a duplicate delivery is a no-op.
    public int $tries = 1;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public readonly Transcription $transcription,
    ) {}

    public function handle(HandleTelegramUpdateAction $action): void
    {
        $transcription = $this->transcription->fresh(['scratchpadEntry']);
        $entry = $transcription?->scratchpadEntry;
        $text = trim((string) $transcription?->text);
        $chatId = $entry?->meta['telegram_chat_id'] ?? null;

        if ($transcription === null || $entry === null || $entry->source !== 'telegram'
            || $transcription->status !== 'done' || $text === '' || ! is_int($chatId)
        ) {
            return;
        }

        $config = TelegramBotConfig::query()->where('workspace_id', $entry->workspace_id)->first();

        if ($config === null
            || ! $config->ai_chat_enabled
            || ! $config->isConnected()
            || ($entry->webhook_generation !== null && $config->webhook_generation !== $entry->webhook_generation)
        ) {
            return;
        }

        if (! Cache::add('telegram:voice-chat:'.$transcription->id, true, now()->addDay())) {
            return;
        }

        $action->handleVoiceTranscript($config, $chatId, $text, $transcription->id);
    }
}
