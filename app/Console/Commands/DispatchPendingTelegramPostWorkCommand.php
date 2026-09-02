<?php

namespace App\Console\Commands;

use App\Actions\Telegram\ClaimTelegramPostWorkAction;
use App\Actions\Telegram\QueueTelegramMessageAction;
use App\Jobs\GenerateTelegramPostJob;
use App\Jobs\ResolveScratchpadLinkJob;
use App\Jobs\TranscribeVoiceNoteJob;
use App\Models\ScratchpadEntry;
use App\Models\TelegramPostRequest;
use App\Models\Transcription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class DispatchPendingTelegramPostWorkCommand extends Command
{
    protected $signature = 'telegram:dispatch-pending-post-work {--limit=100 : Maximum pending requests to inspect}';

    protected $description = 'Enqueue Telegram post-generation work that was not durably dispatched';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $dispatched = 0;
        $claimAction = new ClaimTelegramPostWorkAction;
        $staleAt = now()->subSeconds(ClaimTelegramPostWorkAction::LEASE_SECONDS);
        $claimedRequests = 0;

        TelegramPostRequest::query()
            ->where('state', TelegramPostRequest::GENERATING)
            ->whereNotNull('source_scratchpad_entry_id')
            ->where(function ($query) use ($staleAt): void {
                $query
                    ->whereNull('work_lease_id')
                    ->orWhereNull('work_claimed_at')
                    ->orWhere('work_claimed_at', '<=', $staleAt);
            })
            ->lazyById()
            ->each(function (TelegramPostRequest $candidate) use (&$dispatched, &$claimedRequests, $claimAction, $limit) {
                if ($claimedRequests >= $limit) {
                    return false;
                }

                $leaseId = $claimAction->claim($candidate->id);
                if ($leaseId === null) {
                    return;
                }

                $claimedRequests++;

                $request = TelegramPostRequest::query()
                    ->with('sourceEntry.transcriptions')
                    ->whereKey($candidate->id)
                    ->first();

                if ($request === null) {
                    $claimAction->release($candidate->id, $leaseId);

                    return;
                }

                try {
                    $entry = $request->sourceEntry;
                    if ($entry === null) {
                        $this->failRequest($request, 'The source capture for this post request is missing.', $leaseId);

                        return;
                    }

                    if ($entry->kind === 'link') {
                        $resolvedKind = $entry->meta['resolved_kind'] ?? null;

                        if ($resolvedKind === null) {
                            ResolveScratchpadLinkJob::dispatch($entry, $request->id, $leaseId);
                            $dispatched++;
                        } elseif ($resolvedKind === 'unresolved') {
                            $this->failRequest($request, 'I could not resolve that link, so I could not create the post draft.', $leaseId);
                        } else {
                            GenerateTelegramPostJob::dispatch($request->id, $leaseId);
                            $dispatched++;
                        }

                        return;
                    }

                    if ($entry->kind === 'voice') {
                        $transcription = $entry->transcriptions->first();

                        if ($transcription === null) {
                            $this->failRequest($request, 'The audio transcription record is missing.', $leaseId);
                        } elseif (in_array($transcription->status, ['pending', 'processing'], true)) {
                            TranscribeVoiceNoteJob::dispatch($transcription, $request->id, $leaseId);
                            $dispatched++;
                        } elseif ($transcription->status === 'done') {
                            GenerateTelegramPostJob::dispatch($request->id, $leaseId);
                            $dispatched++;
                        } else {
                            $this->failRequest($request, 'I could not transcribe that audio, so I could not create the post draft.', $leaseId);
                        }

                        return;
                    }

                    GenerateTelegramPostJob::dispatch($request->id, $leaseId);
                    $dispatched++;
                } catch (Throwable $exception) {
                    report($exception);
                    $claimAction->release($request->id, $leaseId);
                }
            });

        Transcription::query()
            ->whereIn('status', ['pending', 'processing'])
            ->whereHas('scratchpadEntry', fn ($query) => $query
                ->where('source', 'telegram')
                ->where('kind', 'voice'))
            ->whereNotExists(function ($query): void {
                $query
                    ->selectRaw('1')
                    ->from('telegram_post_requests')
                    ->whereColumn(
                        'telegram_post_requests.source_scratchpad_entry_id',
                        'transcriptions.scratchpad_entry_id',
                    )
                    ->where('telegram_post_requests.state', TelegramPostRequest::GENERATING);
            })
            ->with('scratchpadEntry')
            ->limit($limit)
            ->lazyById()
            ->each(function (Transcription $transcription) use (&$dispatched): void {
                $entryId = $transcription->scratchpad_entry_id;

                if ($entryId === null) {
                    return;
                }

                try {
                    TranscribeVoiceNoteJob::dispatch($transcription);
                    $dispatched++;
                } catch (Throwable $exception) {
                    report($exception);
                }
            });

        ScratchpadEntry::query()
            ->where('source', 'telegram')
            ->where('kind', 'link')
            ->whereNull('meta->resolved_kind')
            ->whereNotExists(function ($query): void {
                $query
                    ->selectRaw('1')
                    ->from('telegram_post_requests')
                    ->whereColumn(
                        'telegram_post_requests.source_scratchpad_entry_id',
                        'scratchpad_entries.id',
                    )
                    ->where('telegram_post_requests.state', TelegramPostRequest::GENERATING);
            })
            ->limit($limit)
            ->lazyById()
            ->each(function (ScratchpadEntry $entry) use (&$dispatched): void {
                try {
                    ResolveScratchpadLinkJob::dispatch($entry);
                    $dispatched++;
                } catch (Throwable $exception) {
                    report($exception);
                }
            });

        $this->info("Dispatched {$dispatched} pending Telegram post work item(s).");

        return self::SUCCESS;
    }

    private function failRequest(TelegramPostRequest $request, string $message, string $leaseId): void
    {
        DB::transaction(function () use ($request, $message, $leaseId): void {
            $lockedRequest = TelegramPostRequest::query()
                ->with('telegramBotConfig')
                ->whereKey($request->id)
                ->lockForUpdate()
                ->first();

            if ($lockedRequest === null
                || $lockedRequest->state !== TelegramPostRequest::GENERATING
                || $lockedRequest->work_lease_id !== $leaseId
            ) {
                return;
            }

            $lockedRequest->forceFill([
                'state' => TelegramPostRequest::FAILED,
                'error_message' => $message,
                'work_claimed_at' => null,
                'work_lease_id' => null,
            ])->save();

            $config = $lockedRequest->telegramBotConfig;
            if ($config !== null && $config->bot_token !== null) {
                (new QueueTelegramMessageAction)->handle(
                    $config,
                    $lockedRequest->telegram_chat_id,
                    "❌ {$message}",
                    'telegram:post-request:'.$lockedRequest->id.':generation-failure',
                    $lockedRequest->webhook_generation,
                );
            }
        });
    }
}
