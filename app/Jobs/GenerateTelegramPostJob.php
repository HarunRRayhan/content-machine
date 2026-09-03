<?php

namespace App\Jobs;

use App\Actions\Telegram\ClaimTelegramPostWorkAction;
use App\Actions\Telegram\GenerateTelegramPostAction;
use App\Actions\Telegram\QueueTelegramMessageAction;
use App\Models\TelegramBotConfig;
use App\Models\TelegramPostRequest;
use App\Models\Workspace;
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
        if ($this->queue !== 'scratchpad') {
            self::dispatch(
                $this->telegramPostRequestId,
                $this->workLeaseId,
            )->onQueue('scratchpad');

            return;
        }

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

        $message = 'I could not create the post draft because an unexpected error occurred.';
        DB::transaction(function () use ($message): void {
            $reference = TelegramPostRequest::query()
                ->whereKey($this->telegramPostRequestId)
                ->first(['telegram_bot_config_id']);

            if ($reference === null) {
                return;
            }

            $configReference = TelegramBotConfig::query()
                ->whereKey($reference->telegram_bot_config_id)
                ->first(['workspace_id']);

            if ($configReference === null) {
                return;
            }

            Workspace::query()
                ->whereKey($configReference->workspace_id)
                ->lockForUpdate()
                ->first();

            $config = TelegramBotConfig::query()
                ->whereKey($reference->telegram_bot_config_id)
                ->lockForUpdate()
                ->first();
            $lockedRequest = TelegramPostRequest::query()
                ->whereKey($this->telegramPostRequestId)
                ->lockForUpdate()
                ->first();

            if ($config === null || $lockedRequest === null) {
                return;
            }

            if ($lockedRequest->webhook_generation === null && $config->webhook_generation !== null) {
                $lockedRequest->forceFill([
                    'webhook_generation' => $config->webhook_generation,
                ])->save();
            } elseif ($lockedRequest->webhook_generation !== $config->webhook_generation) {
                if ($lockedRequest->state === TelegramPostRequest::GENERATING) {
                    $lockedRequest->forceFill([
                        'state' => TelegramPostRequest::CANCELLED,
                        'cancelled_at' => now(),
                        'error_message' => 'The Telegram bot connection changed before this draft was generated.',
                        'work_claimed_at' => null,
                        'work_lease_id' => null,
                    ])->save();
                }

                return;
            }

            if (! $config->isConnected()
                || $lockedRequest->state !== TelegramPostRequest::GENERATING
                || ($this->workLeaseId !== null
                    && ($lockedRequest->work_lease_id !== $this->workLeaseId
                        || $lockedRequest->work_claimed_at === null
                        || ! $lockedRequest->work_claimed_at->isAfter(now()->subSeconds(ClaimTelegramPostWorkAction::LEASE_SECONDS))))
                || ($this->workLeaseId === null && $lockedRequest->work_lease_id !== null)
            ) {
                return;
            }

            $lockedRequest->forceFill([
                'state' => TelegramPostRequest::FAILED,
                'error_message' => $message,
                'work_claimed_at' => null,
                'work_lease_id' => null,
            ])->save();

            if ($config->bot_token !== null) {
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
