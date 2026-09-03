<?php

namespace App\Console\Commands;

use App\Jobs\ProcessTelegramUpdateJob;
use App\Models\TelegramUpdate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class DispatchPendingTelegramUpdatesCommand extends Command
{
    protected $signature = 'telegram:dispatch-pending-updates {--limit=100 : Maximum pending updates to enqueue} {--retry-failed : Reopen terminal failed updates}';

    protected $description = 'Enqueue Telegram webhook updates that have not finished processing';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $dispatched = 0;

        if ($this->option('retry-failed')) {
            DB::transaction(function () use ($limit): void {
                TelegramUpdate::query()
                    ->whereNull('processed_at')
                    ->whereNull('discarded_at')
                    ->whereNotNull('failed_at')
                    ->whereNotNull('payload')
                    ->orderBy('id')
                    ->limit($limit)
                    ->lock('FOR UPDATE SKIP LOCKED')
                    ->get()
                    ->each(function (TelegramUpdate $update): void {
                        $update->forceFill([
                            'failed_at' => null,
                            'last_error' => null,
                            'dispatch_claimed_at' => null,
                            'dispatch_lease_id' => null,
                            'updated_at' => now(),
                        ])->save();
                    });
            });
        }

        /** @var list<array{id: int, config_id: int, payload: array<string, mixed>, generation: string|null, lease_id: string}> $claims */
        $claims = DB::transaction(function () use ($limit): array {
            $staleAt = now()->subSeconds(ProcessTelegramUpdateJob::DISPATCH_LEASE_SECONDS);
            $claims = [];

            TelegramUpdate::query()
                ->whereNull('processed_at')
                ->whereNull('failed_at')
                ->whereNull('discarded_at')
                ->where(function ($query) use ($staleAt): void {
                    $query->whereNull('dispatch_claimed_at')
                        ->orWhere('dispatch_claimed_at', '<=', $staleAt);
                })
                ->orderBy('id')
                ->limit($limit)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->get()
                ->each(function (TelegramUpdate $update) use (&$claims): void {
                    /** @var mixed $payload */
                    $payload = $update->getAttribute('payload');

                    if ($payload === null) {
                        $update->forceFill([
                            'failed_at' => now(),
                            'last_error' => ProcessTelegramUpdateJob::MISSING_PAYLOAD_ERROR,
                            'dispatch_claimed_at' => null,
                            'dispatch_lease_id' => null,
                            'updated_at' => now(),
                        ])->save();

                        return;
                    }

                    if (! is_array($payload)) {
                        $update->forceFill([
                            'failed_at' => now(),
                            'last_error' => 'The stored Telegram update payload is invalid.',
                            'dispatch_claimed_at' => null,
                            'dispatch_lease_id' => null,
                            'updated_at' => now(),
                        ])->save();

                        return;
                    }

                    $updateId = $payload['update_id'] ?? null;
                    if ((! is_int($updateId) && ! (is_string($updateId) && ctype_digit($updateId)))
                        || (int) $updateId < 0
                        || (int) $updateId !== $update->update_id
                    ) {
                        $update->forceFill([
                            'failed_at' => now(),
                            'last_error' => 'The stored Telegram update payload has no valid update id.',
                            'dispatch_claimed_at' => null,
                            'dispatch_lease_id' => null,
                            'updated_at' => now(),
                        ])->save();

                        return;
                    }

                    $leaseId = (string) Str::uuid();
                    $update->forceFill([
                        'dispatch_claimed_at' => now(),
                        'dispatch_lease_id' => $leaseId,
                    ])->save();

                    $claims[] = [
                        'id' => $update->id,
                        'config_id' => $update->telegram_bot_config_id,
                        'payload' => $payload,
                        'generation' => $update->webhook_generation,
                        'lease_id' => $leaseId,
                    ];
                });

            return $claims;
        });

        foreach ($claims as $claim) {
            try {
                ProcessTelegramUpdateJob::dispatch(
                    $claim['config_id'],
                    $claim['payload'],
                    $claim['generation'],
                    $claim['lease_id'],
                );
                $dispatched++;
            } catch (Throwable $exception) {
                report($exception);

                TelegramUpdate::query()
                    ->whereKey($claim['id'])
                    ->where('dispatch_lease_id', $claim['lease_id'])
                    ->update([
                        'dispatch_claimed_at' => null,
                        'dispatch_lease_id' => null,
                        'updated_at' => now(),
                    ]);
            }
        }

        $this->info("Dispatched {$dispatched} pending Telegram update(s).");

        return self::SUCCESS;
    }
}
