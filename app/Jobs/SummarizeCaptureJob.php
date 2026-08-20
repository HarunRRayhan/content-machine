<?php

namespace App\Jobs;

use App\Actions\Scratchpad\SummarizeCaptureAction;
use App\Models\ScratchpadEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SummarizeCaptureJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly ScratchpadEntry $entry,
    ) {}

    public function handle(SummarizeCaptureAction $action): void
    {
        $action->handle($this->entry);
    }
}
