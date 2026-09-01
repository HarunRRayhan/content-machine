<?php

namespace App\Jobs;

use App\Actions\Scratchpad\TranscribeVoiceNoteAction;
use App\Models\TelegramPostRequest;
use App\Models\Transcription;
use App\Support\Telegram\TelegramClientContract;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class TranscribeVoiceNoteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly Transcription $transcription,
    ) {
        // Reads the audio bytes from the scratchpad uploads volume, which
        // is mounted only on cm-web. Same constraint as ProcessTelegramUpdateJob.
        $this->onQueue('scratchpad');
    }

    public function handle(TranscribeVoiceNoteAction $action): void
    {
        $action->handle($this->transcription);
    }

    /**
     * The action handles expected provider failures itself. This hook covers
     * unexpected exceptions after queue retries so Telegram post requests do
     * not remain in `generating` indefinitely.
     */
    public function failed(Throwable $exception): void
    {
        report($exception);

        $transcription = $this->transcription->fresh(['scratchpadEntry']);
        if ($transcription === null) {
            return;
        }

        $transcription->update([
            'status' => 'failed',
            'error_code' => 'transcription_failed',
            'error_message' => 'The transcription job failed unexpectedly.',
        ]);

        $entry = $transcription->scratchpadEntry;
        if ($entry === null) {
            return;
        }

        $message = 'I could not transcribe that audio, so I could not create the post draft.';
        $requests = TelegramPostRequest::query()
            ->where('source_scratchpad_entry_id', $entry->id)
            ->where('state', TelegramPostRequest::GENERATING)
            ->with('telegramBotConfig')
            ->get();
        $client = app(TelegramClientContract::class);

        foreach ($requests as $request) {
            $updated = TelegramPostRequest::query()
                ->whereKey($request->id)
                ->where('state', TelegramPostRequest::GENERATING)
                ->update([
                    'state' => TelegramPostRequest::FAILED,
                    'error_message' => $message,
                ]);

            if ($updated === 0) {
                continue;
            }

            $config = $request->telegramBotConfig;
            if ($config !== null && $config->bot_token !== null) {
                $client->sendMessage(
                    $config->bot_token,
                    $request->telegram_chat_id,
                    "❌ {$message}",
                );
            }
        }
    }
}
