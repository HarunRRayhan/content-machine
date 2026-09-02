<?php

namespace App\Actions\Telegram;

use App\Models\TelegramBotConfig;
use App\Models\TelegramPostRequest;
use App\Models\Workspace;
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
            $locked = $this->lockCurrentRequest($requestId);
            if ($locked === null) {
                return null;
            }

            $request = $locked['request'];

            if ($request->state !== TelegramPostRequest::GENERATING) {
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
            $locked = $this->lockCurrentRequest($requestId);
            if ($locked === null) {
                return null;
            }

            $request = $locked['request'];

            if ($request->state !== TelegramPostRequest::GENERATING) {
                return null;
            }

            $stale = $request->work_claimed_at === null
                || $request->work_claimed_at->isBefore(now()->subSeconds(self::LEASE_SECONDS));

            if ($leaseId !== null) {
                if ($request->work_lease_id !== $leaseId || $stale) {
                    return null;
                }

                $request->forceFill([
                    'work_claimed_at' => now(),
                ])->save();

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

    public function renew(int $requestId, string $leaseId): bool
    {
        return DB::transaction(function () use ($requestId, $leaseId): bool {
            $locked = $this->lockCurrentRequest($requestId, requireConnected: false);
            if ($locked === null) {
                return false;
            }

            $request = $locked['request'];
            $now = now();

            return $request->state === TelegramPostRequest::GENERATING
                && $request->work_lease_id === $leaseId
                && $request->work_claimed_at !== null
                && $request->work_claimed_at->isAfter($now->copy()->subSeconds(self::LEASE_SECONDS))
                && (bool) $request->forceFill([
                    'work_claimed_at' => $now,
                    'updated_at' => $now,
                ])->save();
        });
    }

    public function clear(int $requestId, ?string $leaseId = null): void
    {
        $query = TelegramPostRequest::query()->whereKey($requestId);

        if ($leaseId !== null) {
            $query->where('work_lease_id', $leaseId);
        } else {
            $query->whereNull('work_lease_id');
        }

        $query->update([
            'work_claimed_at' => null,
            'work_lease_id' => null,
            'updated_at' => now(),
        ]);
    }

    /**
     * Rotation and every post-work finalizer lock the bot config before the
     * request. A request created by the old web fleet may be adopted while
     * that same identity is still current; a different generation fails
     * closed instead.
     *
     * @return array{config: TelegramBotConfig, request: TelegramPostRequest}|null
     */
    private function lockCurrentRequest(int $requestId, bool $requireConnected = true): ?array
    {
        $reference = TelegramPostRequest::query()
            ->whereKey($requestId)
            ->first(['telegram_bot_config_id']);

        if ($reference === null) {
            return null;
        }

        $configReference = TelegramBotConfig::query()
            ->whereKey($reference->telegram_bot_config_id)
            ->first(['workspace_id']);

        if ($configReference === null) {
            return null;
        }

        Workspace::query()
            ->whereKey($configReference->workspace_id)
            ->lockForUpdate()
            ->first();
        $config = TelegramBotConfig::query()
            ->whereKey($reference->telegram_bot_config_id)
            ->lockForUpdate()
            ->first();
        $request = TelegramPostRequest::query()
            ->whereKey($requestId)
            ->lockForUpdate()
            ->first();

        if ($config === null
            || $request === null
            || $request->telegram_bot_config_id !== $config->id
            || ($requireConnected && ! $config->isConnected())
        ) {
            return null;
        }

        if ($request->webhook_generation === null && $config->webhook_generation !== null) {
            $request->forceFill([
                'webhook_generation' => $config->webhook_generation,
            ])->save();
        } elseif ($request->webhook_generation !== $config->webhook_generation) {
            return null;
        }

        return ['config' => $config, 'request' => $request];
    }
}
