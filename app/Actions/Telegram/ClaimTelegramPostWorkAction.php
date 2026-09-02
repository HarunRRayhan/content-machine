<?php

namespace App\Actions\Telegram;

use App\Models\TelegramPostRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gives one queued Telegram post-work item a durable owner. Cache locks still
 * protect the worker while it is running, but this database lease also keeps
 * the minute-by-minute recovery command from enqueueing the same work forever.
 */
class ClaimTelegramPostWorkAction
{
    public const LEASE_SECONDS = 1020;

    public function claim(int $requestId): ?string
    {
        return DB::transaction(function () use ($requestId): ?string {
            $request = TelegramPostRequest::query()
                ->whereKey($requestId)
                ->lockForUpdate()
                ->first();

            if ($request === null || $request->state !== TelegramPostRequest::GENERATING) {
                return null;
            }

            if ($request->work_lease_id !== null
                && $request->work_claimed_at !== null
                && $request->work_claimed_at->isAfter(now()->subSeconds(self::LEASE_SECONDS))
            ) {
                return null;
            }

            $leaseId = (string) Str::uuid();
            $request->forceFill([
                'work_claimed_at' => now(),
                'work_lease_id' => $leaseId,
            ])->save();

            return $leaseId;
        });
    }

    /**
     * Validate a recovery lease, or atomically create one for a direct job
     * that was dispatched before the durable claim was written.
     */
    public function acquire(int $requestId, ?string $leaseId = null): ?string
    {
        return DB::transaction(function () use ($requestId, $leaseId): ?string {
            $request = TelegramPostRequest::query()
                ->whereKey($requestId)
                ->lockForUpdate()
                ->first();

            if ($request === null || $request->state !== TelegramPostRequest::GENERATING) {
                return null;
            }

            $stale = $request->work_claimed_at === null
                || $request->work_claimed_at->isBefore(now()->subSeconds(self::LEASE_SECONDS));

            if ($leaseId !== null) {
                if ($request->work_lease_id !== $leaseId || $stale) {
                    return null;
                }

                return $leaseId;
            }

            if ($request->work_lease_id !== null && ! $stale) {
                return null;
            }

            $newLeaseId = (string) Str::uuid();
            $request->forceFill([
                'work_claimed_at' => now(),
                'work_lease_id' => $newLeaseId,
            ])->save();

            return $newLeaseId;
        });
    }

    public function release(int $requestId, string $leaseId): void
    {
        TelegramPostRequest::query()
            ->whereKey($requestId)
            ->where('work_lease_id', $leaseId)
            ->update([
                'work_claimed_at' => null,
                'work_lease_id' => null,
                'updated_at' => now(),
            ]);
    }

    public function clear(int $requestId, ?string $leaseId = null): void
    {
        $query = TelegramPostRequest::query()->whereKey($requestId);

        if ($leaseId !== null) {
            $query->where('work_lease_id', $leaseId);
        }

        $query->update([
            'work_claimed_at' => null,
            'work_lease_id' => null,
            'updated_at' => now(),
        ]);
    }
}
