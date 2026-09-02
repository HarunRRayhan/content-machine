<?php

namespace App\Jobs;

use App\Actions\Scratchpad\TranscribeVoiceNoteAction;
use App\Actions\Telegram\ClaimTelegramPostWorkAction;
use App\Actions\Telegram\QueueTelegramMessageAction;
use App\Models\TelegramPostRequest;
use App\Models\Transcription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class TranscribeVoiceNoteJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const OVERLAP_EXPIRES_AFTER_SECONDS = 960;

    public const UNIQUE_FOR_SECONDS = 3600;

    /**
     * Old queued payloads do not contain the optional Telegram post-work
     * context.
     */
    public ?int $workRequestId = null;

    public ?string $workLeaseId = null;

    public function __construct(
        public readonly Transcription $transcription,
        ?int $workRequestId = null,
        ?string $workLeaseId = null,
    ) {
        $this->workRequestId = $workRequestId;
        $this->workLeaseId = $workLeaseId;
        // Reads the audio bytes from the scratchpad uploads volume, which
        // is mounted only on cm-web. Same constraint as ProcessTelegramUpdateJob.
        $this->onQueue('scratchpad');
    }

    public function handle(TranscribeVoiceNoteAction $action): void
    {
        $requestId = $this->workRequestId;
        if ($requestId === null) {
            $entryId = $this->transcription->scratchpad_entry_id;
            if ($entryId !== null) {
                $requestId = TelegramPostRequest::query()
                    ->where('source_scratchpad_entry_id', $entryId)
                    ->where('state', TelegramPostRequest::GENERATING)
                    ->value('id');
                $requestId = $requestId === null ? null : (int) $requestId;
            }
        }

        $leaseId = null;
        if ($requestId !== null) {
            $leaseId = (new ClaimTelegramPostWorkAction)->acquire($requestId, $this->workLeaseId);
            if ($leaseId === null) {
                $request = TelegramPostRequest::query()->whereKey($requestId)->first();

                if ($request?->state !== TelegramPostRequest::CANCELLED) {
                    return;
                }

                // Cancellation deliberately leaves the original lease in
                // place so an already-running transcription can finish. If
                // the queued job starts after cancellation, finish the source
                // enrichment independently of the abandoned post request.
                if ($this->workLeaseId !== null) {
                    (new ClaimTelegramPostWorkAction)->clear($requestId, $this->workLeaseId);
                }
                $requestId = null;
                $this->workRequestId = null;
                $this->workLeaseId = null;
            } else {
                $this->workRequestId = $requestId;
                $this->workLeaseId = $leaseId;
            }
        }

        $action->handle($this->transcription, $requestId, $leaseId);

        if ($requestId !== null) {
            (new ClaimTelegramPostWorkAction)->clear($requestId, $leaseId);
        }
    }

    public function uniqueId(): string
    {
        return 'voice-transcription:'.$this->transcription->getKey();
    }

    public function uniqueFor(): int
    {
        return self::UNIQUE_FOR_SECONDS;
    }

    /**
     * Keep duplicate recovery jobs from calling the transcription provider
     * together without making a failed enqueue unrecoverable behind a unique
     * dispatch lock.
     *
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(
                'voice-transcription:'.$this->transcription->getKey(),
                60,
                self::OVERLAP_EXPIRES_AFTER_SECONDS,
            ))->shared()->dontRelease(),
        ];
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

        DB::transaction(function () use ($transcription): void {
            if ($this->workRequestId !== null) {
                $request = TelegramPostRequest::query()
                    ->whereKey($this->workRequestId)
                    ->lockForUpdate()
                    ->first();

                $ownsGeneratingRequest = $request !== null
                    && $request->state === TelegramPostRequest::GENERATING
                    && ($this->workLeaseId !== null
                        ? ($request->work_lease_id === $this->workLeaseId
                            && $request->work_claimed_at !== null
                            && $request->work_claimed_at->isAfter(now()->subSeconds(ClaimTelegramPostWorkAction::LEASE_SECONDS)))
                        : $request->work_lease_id === null);
                $ownsCancelledRequest = $request !== null
                    && $request->state === TelegramPostRequest::CANCELLED
                    && $this->workLeaseId !== null
                    && $request->work_lease_id === $this->workLeaseId;

                if (! $ownsGeneratingRequest && ! $ownsCancelledRequest) {
                    return;
                }
            } elseif ($transcription->scratchpad_entry_id !== null
                && TelegramPostRequest::query()
                    ->where('source_scratchpad_entry_id', $transcription->scratchpad_entry_id)
                    ->where('state', TelegramPostRequest::GENERATING)
                    ->whereNotNull('work_lease_id')
                    ->exists()
            ) {
                // A newer recovery worker owns the post request. Do not mark
                // its shared transcription failed from this stale job.
                return;
            }

            $lockedTranscription = Transcription::query()
                ->with('scratchpadEntry')
                ->whereKey($transcription->id)
                ->lockForUpdate()
                ->first();

            if ($lockedTranscription === null) {
                return;
            }

            if (in_array($lockedTranscription->status, ['done', 'failed'], true)) {
                return;
            }

            $lockedTranscription->forceFill([
                'status' => 'failed',
                'error_code' => 'transcription_failed',
                'error_message' => 'The transcription job failed unexpectedly.',
            ])->save();

            $entry = $lockedTranscription->scratchpadEntry;
            if ($entry === null) {
                return;
            }

            $message = 'I could not transcribe that audio, so I could not create the post draft.';
            $requestQuery = TelegramPostRequest::query()
                ->where('source_scratchpad_entry_id', $entry->id)
                ->where('state', TelegramPostRequest::GENERATING)
                ->with('telegramBotConfig');

            if ($this->workRequestId !== null) {
                $requestQuery->whereKey($this->workRequestId);
            }

            $requestQuery->get()
                ->each(function (TelegramPostRequest $request) use ($message): void {
                    if ($this->workLeaseId !== null) {
                        if ($request->work_lease_id !== $this->workLeaseId) {
                            return;
                        }
                    } elseif ($request->work_lease_id !== null) {
                        return;
                    }

                    $updated = TelegramPostRequest::query()
                        ->whereKey($request->id)
                        ->where('state', TelegramPostRequest::GENERATING)
                        ->when(
                            $this->workLeaseId !== null,
                            fn ($query) => $query->where('work_lease_id', $this->workLeaseId),
                            fn ($query) => $query->whereNull('work_lease_id'),
                        )
                        ->update([
                            'state' => TelegramPostRequest::FAILED,
                            'error_message' => $message,
                            'work_claimed_at' => null,
                            'work_lease_id' => null,
                        ]);

                    if ($updated === 0) {
                        return;
                    }

                    $config = $request->telegramBotConfig;
                    if ($config !== null && $config->bot_token !== null) {
                        (new QueueTelegramMessageAction)->handle(
                            $config,
                            $request->telegram_chat_id,
                            "❌ {$message}",
                            'telegram:post-request:'.$request->id.':transcription-failure',
                            $request->webhook_generation,
                        );
                    }
                });
        });
    }
}
