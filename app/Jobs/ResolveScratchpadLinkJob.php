<?php

namespace App\Jobs;

use App\Actions\Scratchpad\ResolveScratchpadLinkAction;
use App\Models\ScratchpadEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ResolveScratchpadLinkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly ScratchpadEntry $entry,
    ) {}

    public function handle(ResolveScratchpadLinkAction $action): void
    {
        $action->handle($this->entry);
    }

    /**
     * After the worker's own retries (see deploy/docker/supervisord.conf)
     * are exhausted, leave the entry honestly marked rather than silently
     * stuck forever looking unresolved. The exception itself is reported
     * to the log, not into meta: meta is rendered back to the user, and an
     * exception message is debugging detail, not something to show him.
     */
    public function failed(Throwable $exception): void
    {
        report($exception);

        $this->entry->update([
            'meta' => [
                ...$this->entry->meta,
                'resolved_via' => 'metadata only (resolution failed)',
                'resolved_kind' => 'unresolved',
            ],
        ]);
    }
}
