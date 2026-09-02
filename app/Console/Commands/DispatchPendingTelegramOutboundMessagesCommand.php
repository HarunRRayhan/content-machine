<?php

namespace App\Console\Commands;

use App\Jobs\SendTelegramOutboundMessageJob;
use App\Models\TelegramOutboundMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class DispatchPendingTelegramOutboundMessagesCommand extends Command
{
    protected $signature = 'telegram:dispatch-pending-outbound-messages {--limit=100 : Maximum pending messages to enqueue} {--retry-failed : Reopen terminal failed messages}';

    protected $description = 'Enqueue Telegram replies that have not finished sending';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $dispatched = 0;

        if ($this->option('retry-failed')) {
            $failedIds = TelegramOutboundMessage::query()
                ->where('status', TelegramOutboundMessage::FAILED)
                ->limit($limit)
                ->pluck('id');

            TelegramOutboundMessage::query()
                ->whereIn('id', $failedIds)
                ->update([
                    'status' => TelegramOutboundMessage::PENDING,
                    'failed_at' => null,
                    'last_error' => null,
                    'next_attempt_at' => null,
                    'last_attempt_at' => null,
                    'dispatch_claimed_at' => null,
                    'dispatch_lease_id' => null,
                    'sent_at' => null,
                    'updated_at' => now(),
                ]);
        }

        /** @var list<array{id: int, lease_id: string}> $claims */
        $claims = DB::transaction(function () use ($limit): array {
            $staleAt = now()->subSeconds(SendTelegramOutboundMessageJob::DISPATCH_LEASE_SECONDS);
            $claims = [];

            TelegramOutboundMessage::query()
                ->where('status', TelegramOutboundMessage::PENDING)
                ->where(function ($query): void {
                    $query->whereNull('next_attempt_at')
                        ->orWhere('next_attempt_at', '<=', now());
                })
                ->where(function ($query) use ($staleAt): void {
                    $query->whereNull('dispatch_claimed_at')
                        ->orWhere('dispatch_claimed_at', '<=', $staleAt);
                })
                ->orderBy('id')
                ->limit($limit)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->get()
                ->each(function (TelegramOutboundMessage $message) use (&$claims): void {
                    $leaseId = (string) Str::uuid();
                    $message->forceFill([
                        'dispatch_claimed_at' => now(),
                        'dispatch_lease_id' => $leaseId,
                    ])->save();

                    $claims[] = ['id' => $message->id, 'lease_id' => $leaseId];
                });

            return $claims;
        });

        foreach ($claims as $claim) {
            try {
                SendTelegramOutboundMessageJob::dispatch($claim['id'], $claim['lease_id']);
                $dispatched++;
            } catch (Throwable $exception) {
                report($exception);

                TelegramOutboundMessage::query()
                    ->whereKey($claim['id'])
                    ->where('dispatch_lease_id', $claim['lease_id'])
                    ->update([
                        'dispatch_claimed_at' => null,
                        'dispatch_lease_id' => null,
                        'updated_at' => now(),
                    ]);
            }
        }

        $this->info("Dispatched {$dispatched} pending Telegram outbound message(s).");

        return self::SUCCESS;
    }
}
