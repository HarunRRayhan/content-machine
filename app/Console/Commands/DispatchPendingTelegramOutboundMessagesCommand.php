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
    protected $signature = 'telegram:dispatch-pending-outbound-messages {--limit=100 : Maximum pending messages to enqueue} {--retry-failed : Reopen terminal failed messages} {--retry-uncertain : Reopen verified uncertain messages} {--verified-not-delivered-id=* : Specific uncertain row ids verified absent from Telegram}';

    protected $description = 'Enqueue Telegram replies that have not finished sending';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $dispatched = 0;
        $staleAt = now()->subSeconds(SendTelegramOutboundMessageJob::DISPATCH_LEASE_SECONDS);
        $verifiedUncertainIds = $this->verifiedUncertainIds();

        if ($this->option('retry-uncertain') && $verifiedUncertainIds === []) {
            $this->error('Pass --verified-not-delivered-id for every uncertain row before retrying it.');

            return self::FAILURE;
        }

        if (! $this->option('retry-uncertain') && $verifiedUncertainIds !== []) {
            $this->error('--verified-not-delivered-id requires --retry-uncertain.');

            return self::FAILURE;
        }

        if ($verifiedUncertainIds !== []) {
            $staleSendingAt = now()->subSeconds(SendTelegramOutboundMessageJob::DISPATCH_LEASE_SECONDS);
            $verifiedMessages = TelegramOutboundMessage::query()
                ->whereIn('id', $verifiedUncertainIds)
                ->get(['id', 'status', 'dispatch_claimed_at']);

            if ($verifiedMessages->count() !== count($verifiedUncertainIds)
                || $verifiedMessages->contains(function (TelegramOutboundMessage $message) use ($staleSendingAt): bool {
                    return $message->status !== TelegramOutboundMessage::UNCERTAIN
                        && ! ($message->status === TelegramOutboundMessage::SENDING
                            && ($message->dispatch_claimed_at === null
                                || $message->dispatch_claimed_at->lessThanOrEqualTo($staleSendingAt)));
                })
            ) {
                $this->error('Every --verified-not-delivered-id must name an uncertain or stale-sending Telegram outbound row.');

                return self::FAILURE;
            }
        }

        /** @var list<int> $staleSendingIds */
        $staleSendingIds = DB::transaction(function () use ($limit, $staleAt): array {
            $messages = TelegramOutboundMessage::query()
                ->where('status', TelegramOutboundMessage::SENDING)
                ->where(function ($query) use ($staleAt): void {
                    $query->whereNull('dispatch_claimed_at')
                        ->orWhere('dispatch_claimed_at', '<=', $staleAt);
                })
                ->orderBy('id')
                ->limit($limit)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->get();

            $ids = [];
            foreach ($messages as $message) {
                $message->forceFill([
                    'status' => TelegramOutboundMessage::UNCERTAIN,
                    'failed_at' => now(),
                    'last_error' => 'Telegram delivery outcome is uncertain after the worker stopped. Verify the chat before retrying.',
                    'dispatch_claimed_at' => null,
                    'dispatch_lease_id' => null,
                    'next_attempt_at' => null,
                    'updated_at' => now(),
                ])->save();
                $ids[] = $message->id;
            }

            return $ids;
        });

        if ($staleSendingIds !== []) {
            $this->warn('Marked '.count($staleSendingIds).' Telegram outbound message(s) uncertain after an interrupted send.');
        }

        $retryFailed = (bool) $this->option('retry-failed');
        $retryUncertain = (bool) $this->option('retry-uncertain');

        if ($retryFailed || $retryUncertain) {
            DB::transaction(function () use ($retryFailed, $retryUncertain, $verifiedUncertainIds, $staleSendingIds, $limit): void {
                $retryIds = TelegramOutboundMessage::query()
                    ->where(function ($query) use ($retryFailed, $retryUncertain, $verifiedUncertainIds): void {
                        if ($retryFailed) {
                            $query->where('status', TelegramOutboundMessage::FAILED);
                        }

                        if ($retryUncertain) {
                            $method = $retryFailed ? 'orWhere' : 'where';
                            $query->{$method}(function ($uncertain) use ($verifiedUncertainIds): void {
                                $uncertain
                                    ->where('status', TelegramOutboundMessage::UNCERTAIN)
                                    ->whereIn('id', $verifiedUncertainIds);
                            });
                        }
                    })
                    ->whereNull('dispatch_lease_id')
                    ->when(
                        $staleSendingIds !== [],
                        fn ($query) => $query->whereNotIn('id', $staleSendingIds),
                    )
                    ->orderBy('id')
                    ->limit($limit)
                    ->lock('FOR UPDATE SKIP LOCKED')
                    ->pluck('id');

                if ($retryIds->isEmpty()) {
                    return;
                }

                TelegramOutboundMessage::query()
                    ->whereIn('id', $retryIds)
                    ->where(function ($query) use ($retryFailed, $retryUncertain, $verifiedUncertainIds): void {
                        if ($retryFailed) {
                            $query->where('status', TelegramOutboundMessage::FAILED);
                        }

                        if ($retryUncertain) {
                            $method = $retryFailed ? 'orWhere' : 'where';
                            $query->{$method}(function ($uncertain) use ($verifiedUncertainIds): void {
                                $uncertain
                                    ->where('status', TelegramOutboundMessage::UNCERTAIN)
                                    ->whereIn('id', $verifiedUncertainIds);
                            });
                        }
                    })
                    ->whereNull('dispatch_lease_id')
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
            });
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

    /**
     * @return list<int>
     */
    private function verifiedUncertainIds(): array
    {
        $ids = [];
        foreach ((array) $this->option('verified-not-delivered-id') as $value) {
            foreach (preg_split('/\s*,\s*/', (string) $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                if (! ctype_digit($part) || (int) $part < 1) {
                    $this->error("Invalid Telegram outbound message id [{$part}].");

                    return [];
                }

                $ids[] = (int) $part;
            }
        }

        return array_values(array_unique($ids));
    }
}
