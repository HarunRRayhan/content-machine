<?php

namespace App\Jobs;

use App\Actions\Scratchpad\ResolveScratchpadLinkAction;
use App\Actions\Telegram\ClaimTelegramPostWorkAction;
use App\Actions\Telegram\QueueTelegramMessageAction;
use App\Models\ScratchpadEntry;
use App\Models\TelegramPostRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class ResolveScratchpadLinkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const OVERLAP_EXPIRES_AFTER_SECONDS = 960;

    /**
     * Old queued payloads do not contain the optional Telegram post-work
     * context.
     */
    public ?int $workRequestId = null;

    public ?string $workLeaseId = null;

    public function __construct(
        public readonly ScratchpadEntry $entry,
        ?int $workRequestId = null,
        ?string $workLeaseId = null,
    ) {
        $this->workRequestId = $workRequestId;
        $this->workLeaseId = $workLeaseId;
    }

    /**
     * Summarization only runs after a genuine resolution (never for
     * 'unresolved', which has no scraped title/description to summarize
     * in the first place).
     */
    public function handle(ResolveScratchpadLinkAction $action): void
    {
        $requestId = $this->workRequestId;
        if ($requestId === null) {
            $requestIds = TelegramPostRequest::query()
                ->where('source_scratchpad_entry_id', $this->entry->id)
                ->where('state', TelegramPostRequest::GENERATING)
                ->orderBy('id')
                ->pluck('id');

            foreach ($requestIds as $candidateId) {
                $candidateId = (int) $candidateId;
                $leaseId = (new ClaimTelegramPostWorkAction)->acquire($candidateId);
                if ($leaseId !== null) {
                    $requestId = $candidateId;
                    $this->workRequestId = $candidateId;
                    $this->workLeaseId = $leaseId;

                    break;
                }
            }

            if ($requestIds->isNotEmpty() && $requestId === null) {
                return;
            }
        } else {
            $leaseId = (new ClaimTelegramPostWorkAction)->acquire($requestId, $this->workLeaseId);
            if ($leaseId === null) {
                return;
            }

            $this->workLeaseId = $leaseId;
        }

        $action->handle($this->entry);

        if ($requestId !== null
            && $this->workLeaseId !== null
            && ! (new ClaimTelegramPostWorkAction)->renew($requestId, $this->workLeaseId)
        ) {
            return;
        }

        if (($this->entry->meta['resolved_kind'] ?? null) !== 'unresolved') {
            SummarizeCaptureJob::dispatch($this->entry);
            $this->queueGeneration($requestId, $this->workLeaseId);

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

        DB::transaction(function (): void {
            // Lock every request sharing this capture before changing the
            // entry. An old serialized job must not mark a newer worker's
            // shared source unresolved while that worker is resolving it.
            $requests = TelegramPostRequest::query()
                ->where('source_scratchpad_entry_id', $this->entry->id)
                ->where('state', TelegramPostRequest::GENERATING)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($this->workRequestId !== null) {
                $request = $requests->firstWhere('id', $this->workRequestId);

                if ($request === null || ! $this->ownsPostWork($request)) {
                    return;
                }
            } elseif ($requests->contains(fn (TelegramPostRequest $request): bool => $request->work_lease_id !== null)) {
                return;
            }

            $entry = ScratchpadEntry::query()
                ->whereKey($this->entry->id)
                ->lockForUpdate()
                ->first();

            if ($entry === null) {
                return;
            }

            $entry->forceFill([
                'meta' => [
                    ...$entry->meta,
                    'resolved_via' => 'metadata only (resolution failed)',
                    'resolved_kind' => 'unresolved',
                ],
            ])->save();

            $this->failTelegramPostRequests(
                'I could not resolve that link, so I could not create the post draft.',
            );
        });
    }

    private function failTelegramPostRequests(string $message): void
    {
        $requests = TelegramPostRequest::query()
            ->where('source_scratchpad_entry_id', $this->entry->id)
            ->where('state', TelegramPostRequest::GENERATING)
            ->with('telegramBotConfig')
            ->get();

        foreach ($requests as $request) {
            DB::transaction(function () use ($request, $message): void {
                $lockedRequest = TelegramPostRequest::query()
                    ->with('telegramBotConfig')
                    ->whereKey($request->id)
                    ->lockForUpdate()
                    ->first();

                if ($lockedRequest === null
                    || $lockedRequest->state !== TelegramPostRequest::GENERATING
                ) {
                    return;
                }

                if (! $this->ownsPostWork($lockedRequest)) {
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
                        'telegram:post-request:'.$lockedRequest->id.':link-failure',
                        $lockedRequest->webhook_generation,
                    );
                }
            });
        }
    }

    private function queueGeneration(?int $currentRequestId, ?string $currentLeaseId): void
    {
        $claimAction = new ClaimTelegramPostWorkAction;

        TelegramPostRequest::query()
            ->where('source_scratchpad_entry_id', $this->entry->id)
            ->where('state', TelegramPostRequest::GENERATING)
            ->get()
            ->each(function (TelegramPostRequest $request) use ($claimAction, $currentRequestId, $currentLeaseId): void {
                if ($request->id === $currentRequestId && $currentLeaseId !== null) {
                    $claimAction->release($request->id, $currentLeaseId);
                }

                $leaseId = $claimAction->claim($request->id);
                if ($leaseId === null) {
                    return;
                }

                GenerateTelegramPostJob::dispatch($request->id, $leaseId);
            });
    }

    private function ownsPostWork(TelegramPostRequest $request): bool
    {
        if ($this->workLeaseId === null) {
            return $request->work_lease_id === null;
        }

        return $request->work_lease_id === $this->workLeaseId
            && $request->work_claimed_at !== null
            && $request->work_claimed_at->isAfter(now()->subSeconds(ClaimTelegramPostWorkAction::LEASE_SECONDS));
    }
}
