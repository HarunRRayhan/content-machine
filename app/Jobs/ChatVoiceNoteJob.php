<?php

namespace App\Jobs;

use App\Actions\Scratchpad\DeleteScratchpadEntryAction;
use App\Actions\Telegram\HandleTelegramUpdateAction;
use App\Models\ScratchpadEntry;
use App\Models\TelegramBotConfig;
use App\Models\Transcription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Runs a Telegram voice note's transcript through the same AI-chat pipeline
 * a typed message uses. Dispatched once, when the transcription flips to done.
 */
class ChatVoiceNoteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Chat can save or delete notes: never retry, and claim the run durably so a duplicate delivery is a no-op.
    public int $tries = 1;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public readonly Transcription $transcription,
    ) {}

    public function handle(HandleTelegramUpdateAction $action, DeleteScratchpadEntryAction $deleteEntry): void
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

        $claimed = DB::transaction(function () use ($entry, $transcription): bool {
            $locked = ScratchpadEntry::query()->whereKey($entry->id)->lockForUpdate()->first();

            if ($locked === null || ($locked->meta['voice_chat_transcription_id'] ?? null) === $transcription->id) {
                return false;
            }

            $locked->update(['meta' => [...$locked->meta, 'voice_chat_transcription_id' => $transcription->id]]);

            return true;
        });

        // A question or command the bot answered is not a capture: drop the entry and its audio, like typed text.
        if ($claimed
            && $action->handleVoiceTranscript($config, $chatId, $text, $transcription->id, $entry->webhook_generation)
        ) {
            $fresh = $entry->fresh();

            if ($fresh !== null && $fresh->status === 'new') {
                $deleteEntry->handle($fresh);
            }
        }
    }
}
