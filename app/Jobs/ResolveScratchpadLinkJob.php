<?php

namespace App\Jobs;

use App\Actions\Scratchpad\ResolveScratchpadLinkAction;
use App\Models\ScratchpadEntry;
use App\Models\TelegramPostRequest;
use App\Support\Telegram\TelegramClientContract;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ResolveScratchpadLinkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const OVERLAP_EXPIRES_AFTER_SECONDS = 960;

    public function __construct(
        public readonly ScratchpadEntry $entry,
    ) {}

    /**
     * Summarization only runs after a genuine resolution (never for
     * 'unresolved', which has no scraped title/description to summarize
     * in the first place).
     */
    public function handle(ResolveScratchpadLinkAction $action): void
    {
        $action->handle($this->entry);

        if (($this->entry->meta['resolved_kind'] ?? null) !== 'unresolved') {
            SummarizeCaptureJob::dispatch($this->entry);

            TelegramPostRequest::query()
                ->where('source_scratchpad_entry_id', $this->entry->id)
                ->where('state', TelegramPostRequest::GENERATING)
                ->get()
                ->each(fn (TelegramPostRequest $request) => GenerateTelegramPostJob::dispatch($request->id));

            return;
        }

        $this->failTelegramPostRequests(
            'I could not resolve that link, so I could not create the post draft.',
        );
    }

    public function uniqueId(): string
    {
        return 'scratchpad-link-resolution:'.$this->entry->getKey();
    }

    /**
     * Duplicate recovery dispatches must not resolve the same URL together,
     * but an enqueue failure must remain recoverable from the database row.
     *
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(
                'scratchpad-link-resolution:'.$this->entry->getKey(),
                60,
                self::OVERLAP_EXPIRES_AFTER_SECONDS,
            ))->shared()->dontRelease(),
        ];
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

        $this->failTelegramPostRequests(
            'I could not resolve that link, so I could not create the post draft.',
        );
    }

    private function failTelegramPostRequests(string $message): void
    {
        $requests = TelegramPostRequest::query()
            ->where('source_scratchpad_entry_id', $this->entry->id)
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
