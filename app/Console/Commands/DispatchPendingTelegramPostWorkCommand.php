<?php

namespace App\Console\Commands;

use App\Jobs\GenerateTelegramPostJob;
use App\Jobs\ResolveScratchpadLinkJob;
use App\Jobs\TranscribeVoiceNoteJob;
use App\Models\TelegramPostRequest;
use App\Support\Telegram\TelegramClientContract;
use Illuminate\Console\Command;

class DispatchPendingTelegramPostWorkCommand extends Command
{
    protected $signature = 'telegram:dispatch-pending-post-work {--limit=100 : Maximum pending requests to inspect}';

    protected $description = 'Enqueue Telegram post-generation work that was not durably dispatched';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $dispatched = 0;

        TelegramPostRequest::query()
            ->where('state', TelegramPostRequest::GENERATING)
            ->whereNotNull('source_scratchpad_entry_id')
            ->with('sourceEntry.transcriptions')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (TelegramPostRequest $request) use (&$dispatched): void {
                $entry = $request->sourceEntry;
                if ($entry === null) {
                    $this->failRequest($request, 'The source capture for this post request is missing.');

                    return;
                }

                if ($entry->kind === 'link') {
                    $resolvedKind = $entry->meta['resolved_kind'] ?? null;

                    if ($resolvedKind === null) {
                        ResolveScratchpadLinkJob::dispatch($entry);
                        $dispatched++;
                    } elseif ($resolvedKind === 'unresolved') {
                        $this->failRequest($request, 'I could not resolve that link, so I could not create the post draft.');
                    } else {
                        GenerateTelegramPostJob::dispatch($request->id);
                        $dispatched++;
                    }

                    return;
                }

                if ($entry->kind === 'voice') {
                    $transcription = $entry->transcriptions->first();

                    if ($transcription === null) {
                        $this->failRequest($request, 'The audio transcription record is missing.');
                    } elseif (in_array($transcription->status, ['pending', 'processing'], true)) {
                        TranscribeVoiceNoteJob::dispatch($transcription);
                        $dispatched++;
                    } elseif ($transcription->status === 'done') {
                        GenerateTelegramPostJob::dispatch($request->id);
                        $dispatched++;
                    } else {
                        $this->failRequest($request, 'I could not transcribe that audio, so I could not create the post draft.');
                    }

                    return;
                }

                GenerateTelegramPostJob::dispatch($request->id);
                $dispatched++;
            });

        $this->info("Dispatched {$dispatched} pending Telegram post work item(s).");

        return self::SUCCESS;
    }

    private function failRequest(TelegramPostRequest $request, string $message): void
    {
        $updated = TelegramPostRequest::query()
            ->whereKey($request->id)
            ->where('state', TelegramPostRequest::GENERATING)
            ->update([
                'state' => TelegramPostRequest::FAILED,
                'error_message' => $message,
            ]);

        if ($updated === 0) {
            return;
        }

        $config = $request->telegramBotConfig;
        if ($config !== null && $config->bot_token !== null) {
            app(TelegramClientContract::class)->sendMessage(
                $config->bot_token,
                $request->telegram_chat_id,
                "❌ {$message}",
            );
        }
    }
}
