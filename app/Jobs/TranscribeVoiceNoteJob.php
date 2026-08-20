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
    ) {}

    public function handle(TranscribeVoiceNoteAction $action): void
    {
        $action->handle($this->transcription);
    }
}
