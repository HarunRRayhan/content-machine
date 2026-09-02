<?php

namespace App\Jobs;

use App\Actions\Telegram\SendTelegramOutboundMessageAction;
use App\Models\TelegramOutboundMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class SendTelegramOutboundMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const TIMEOUT_SECONDS = 30;

    public const OVERLAP_EXPIRES_AFTER_SECONDS = 60;

    public const DISPATCH_LEASE_SECONDS = 120;

    public int $timeout = self::TIMEOUT_SECONDS;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60, 300];

    /**
     * Old queued payloads do not contain the recovery dispatch lease.
     */
    public ?string $dispatchLeaseId = null;

    public function __construct(
        public readonly int $telegramOutboundMessageId,
        ?string $dispatchLeaseId = null,
    ) {
        $this->dispatchLeaseId = $dispatchLeaseId;
        $this->onQueue('default');
    }

    public function handle(SendTelegramOutboundMessageAction $action): void
    {
        $action->handle($this->telegramOutboundMessageId, $this->dispatchLeaseId);
    }

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(
                'telegram-outbound:'.$this->telegramOutboundMessageId,
                60,
                self::OVERLAP_EXPIRES_AFTER_SECONDS,
            ))->shared()->dontRelease(),
        ];
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            report($exception);
        }

        // A pre-lease serialized job cannot prove that it still owns a
        // pending row. The outbox dispatcher will recover it by state instead.
        if ($this->dispatchLeaseId === null) {
            return;
        }

        $recorded = DB::transaction(function (): bool {
            $query = TelegramOutboundMessage::query()
                ->whereKey($this->telegramOutboundMessageId)
                ->whereIn('status', [
                    TelegramOutboundMessage::PENDING,
                    TelegramOutboundMessage::SENDING,
                ])
                ->whereNull('discarded_at');

            if ($this->dispatchLeaseId === null) {
                $query->whereNull('dispatch_lease_id');
            } else {
                $query->where('dispatch_lease_id', $this->dispatchLeaseId);
            }

            $message = $query->lockForUpdate()->first();

            if ($message === null) {
                return false;
            }

            $message->forceFill([
                'status' => $message->status === TelegramOutboundMessage::SENDING
                    ? TelegramOutboundMessage::UNCERTAIN
                    : TelegramOutboundMessage::FAILED,
                'failed_at' => now(),
                'last_error' => $message->status === TelegramOutboundMessage::SENDING
                    ? 'Telegram delivery outcome is uncertain. Verify the chat before retrying.'
                    : $message->last_error,
                'dispatch_claimed_at' => null,
                'dispatch_lease_id' => null,
                'updated_at' => now(),
            ])->save();

            return true;
        });

        unset($recorded);
    }
}
