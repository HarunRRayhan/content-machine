<?php

namespace App\Jobs;

use App\Actions\Scratchpad\TranscribeVoiceNoteAction;
use App\Models\Transcription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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
}
