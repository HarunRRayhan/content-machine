<?php

namespace App\Jobs;

use App\Actions\Telegram\ClaimTelegramPostWorkAction;
use App\Actions\Telegram\GenerateTelegramPostAction;
use App\Actions\Telegram\QueueTelegramMessageAction;
use App\Models\TelegramPostRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class GenerateTelegramPostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const OVERLAP_EXPIRES_AFTER_SECONDS = 960;

    /**
     * Old queued payloads do not contain the durable work lease.
     */
    public ?string $workLeaseId = null;

    public function __construct(
        public readonly int $telegramPostRequestId,
        ?string $workLeaseId = null,
    ) {
        $this->workLeaseId = $workLeaseId;
        // Generated photo drafts read the scratchpad uploads volume. Keeping
        // every generation here also avoids a text/photo queue race.
        $this->onQueue('scratchpad');
    }

    public function handle(GenerateTelegramPostAction $action): void
    {
        $requestExists = TelegramPostRequest::query()
            ->whereKey($this->telegramPostRequestId)
            ->exists();

        if (! $requestExists) {
            // Keep the small delegation contract useful for old serialized
            // jobs and unit tests whose request row is intentionally absent.
            $action->handle($this->telegramPostRequestId);

            return;
        }

        $leaseId = (new ClaimTelegramPostWorkAction)->acquire(
            $this->telegramPostRequestId,
            $this->workLeaseId,
        );

        if ($leaseId === null) {
            return;
        }

        $this->workLeaseId = $leaseId;
        $action->handle($this->telegramPostRequestId, $leaseId);
        (new ClaimTelegramPostWorkAction)->clear($this->telegramPostRequestId, $leaseId);
    }

    public function uniqueId(): string
    {
        return 'telegram-post-generation:'.$this->telegramPostRequestId;
    }

    /**
     * The request row is the durable completion guard. This lock only keeps
     * duplicate recovery dispatches from calling the AI provider together.
     *
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(
                'telegram-post-generation:'.$this->telegramPostRequestId,
                60,
                self::OVERLAP_EXPIRES_AFTER_SECONDS,
            ))->shared()->dontRelease(),
        ];
    }

    /**
     * Do not leave a request stuck in `generating` when an unexpected error
     * survives the queue worker's retries. Expected provider/storage failures
     * are handled by GenerateTelegramPostAction itself.
     */
    public function failed(Throwable $exception): void
    {
        report($exception);

        $request = TelegramPostRequest::query()
            ->whereKey($this->telegramPostRequestId)
            ->where('state', TelegramPostRequest::GENERATING)
            ->with('telegramBotConfig')
            ->first();

        if ($request === null) {
            return;
        }

        $message = 'I could not create the post draft because an unexpected error occurred.';
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

            if ($this->workLeaseId !== null
                && $lockedRequest->work_lease_id !== $this->workLeaseId
            ) {
                return;
            }

            if ($this->workLeaseId !== null
                && ($lockedRequest->work_claimed_at === null
                    || $lockedRequest->work_claimed_at->isBefore(now()->subSeconds(ClaimTelegramPostWorkAction::LEASE_SECONDS)))
            ) {
                return;
            }

            if ($this->workLeaseId === null && $lockedRequest->work_lease_id !== null) {
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
