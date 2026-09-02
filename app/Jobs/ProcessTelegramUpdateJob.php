<?php

namespace App\Jobs;

use App\Actions\Telegram\HandleTelegramUpdateAction;
use App\Models\TelegramBotConfig;
use App\Models\TelegramUpdate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Text, link, and command updates use the supervised default queue. Photo,
 * voice, and audio updates stay on scratchpad because their media files live
 * on cm-web.
 */
class ProcessTelegramUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const OVERLAP_EXPIRES_AFTER_SECONDS = 960;

    public const DISPATCH_LEASE_SECONDS = 1020;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60, 300];

    /**
     * Old queued payloads do not contain generation-aware webhook fields.
     * Keep the new field explicitly initialized so those payloads deserialize
     * without an uninitialized typed-property error.
     */
    public ?string $webhookGeneration = null;

    /**
     * Old queued payloads do not contain the recovery dispatch lease.
     */
    public ?string $dispatchLeaseId = null;

    /**
     * @param  array<string, mixed>  $update
     */
    public function __construct(
        public readonly int $telegramBotConfigId,
        public readonly array $update,
        ?string $webhookGeneration = null,
        ?string $dispatchLeaseId = null,
    ) {
        $this->webhookGeneration = $webhookGeneration;
        $this->dispatchLeaseId = $dispatchLeaseId;
        $this->onQueue('default');

        $message = $update['message'] ?? null;

        if (is_array($message) && (isset($message['photo']) || isset($message['voice']) || isset($message['audio']))) {
            // Photo/voice captures write into the scratchpad uploads volume,
            // which is mounted only on cm-web (Railway volumes are one-service).
            // cm-worker's default queue has no volume, so media updates must
            // stay on the scratchpad queue that cm-web consumes.
            $this->onQueue('scratchpad');
        }
    }

    public function handle(HandleTelegramUpdateAction $action): void
    {
        $updateId = $this->update['update_id'] ?? null;
        if (! is_int($updateId) && ! (is_string($updateId) && ctype_digit($updateId))) {
            return;
        }
        $updateId = (int) $updateId;

        /** @var array{config: TelegramBotConfig|null, record: TelegramUpdate|null, payload: array<string, mixed>|null}|null $state */
        $state = DB::transaction(function () use ($updateId): ?array {
            $config = TelegramBotConfig::query()
                ->whereKey($this->telegramBotConfigId)
                ->lockForUpdate()
                ->first();

            $recordQuery = TelegramUpdate::query()
                ->where('telegram_bot_config_id', $this->telegramBotConfigId)
                ->where('update_id', (int) $updateId);

            if ($this->webhookGeneration !== null) {
                $recordQuery->where('webhook_generation', $this->webhookGeneration);
            }

            $record = $recordQuery->lockForUpdate()->first();

            if ($record === null
                || $record->processed_at !== null
                || $record->failed_at !== null
                || $record->discarded_at !== null
            ) {
                return null;
            }

            $staleAt = now()->subSeconds(self::DISPATCH_LEASE_SECONDS);
            $claimExpired = $record->dispatch_claimed_at === null
                || $record->dispatch_claimed_at->lessThanOrEqualTo($staleAt);

            if ($this->dispatchLeaseId !== null) {
                if ($record->dispatch_lease_id !== $this->dispatchLeaseId
                    || $record->dispatch_claimed_at === null
                    || $claimExpired
                ) {
                    return null;
                }
            } elseif ($record->dispatch_lease_id !== null && ! $claimExpired) {
                return null;
            }

            if ($this->dispatchLeaseId === null) {
                $this->dispatchLeaseId = (string) Str::uuid();
            }

            if ($config === null || ! $config->isConnected()) {
                $this->markDiscarded($record, 'The Telegram bot connection is no longer available.');

                return null;
            }

            if ($record->webhook_generation === null) {
                // Rows accepted by the old web process during the expand
                // phase are safe to adopt only under the still-current bot
                // identity. This preserves them instead of silently dropping
                // a queued update.
                $record->forceFill([
                    'webhook_generation' => $config->webhook_generation,
                ])->save();
            } elseif ($record->webhook_generation !== $config->webhook_generation) {
                $this->markDiscarded($record, 'The Telegram webhook identity changed before this update was processed.');

                return null;
            }

            $payload = is_array($record->payload) ? $record->payload : $this->update;
            $record->forceFill([
                'dispatch_claimed_at' => now(),
                'dispatch_lease_id' => $this->dispatchLeaseId,
            ])->save();

            return ['config' => $config, 'record' => $record, 'payload' => $payload];
        });

        if ($state === null || $state['config'] === null || $state['record'] === null || $state['payload'] === null) {
            return;
        }

        $record = $state['record'];
        $config = $state['config'];
        $payload = $state['payload'];
        $action->handle($config, $payload, $this->dispatchLeaseId);
        $this->markProcessed($record);
    }

    /**
     * Keep duplicate webhook deliveries from running concurrently, while the
     * persisted completion marker prevents a later duplicate after the first
     * job has finished.
     *
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId(), 60, self::OVERLAP_EXPIRES_AFTER_SECONDS))
                ->shared()
                ->dontRelease(),
        ];
    }

    public function uniqueId(): string
    {
        $updateId = $this->update['update_id'] ?? hash('sha256', serialize($this->update));
        $generation = $this->webhookGeneration ?? 'legacy';

        return 'telegram-update:'.$this->telegramBotConfigId.':'.$generation.':'.$updateId;
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            report($exception);
        }

        $updateId = $this->update['update_id'] ?? null;
        if (! is_int($updateId) && ! (is_string($updateId) && ctype_digit($updateId))) {
            return;
        }
        $updateId = (int) $updateId;

        DB::transaction(function () use ($updateId, $exception): void {
            $query = TelegramUpdate::query()
                ->where('telegram_bot_config_id', $this->telegramBotConfigId)
                ->where('update_id', (int) $updateId)
                ->whereNull('processed_at')
                ->whereNull('failed_at')
                ->whereNull('discarded_at');

            if ($this->webhookGeneration !== null) {
                $query->where('webhook_generation', $this->webhookGeneration);
            }

            if ($this->dispatchLeaseId !== null) {
                $query->where('dispatch_lease_id', $this->dispatchLeaseId);
            } else {
                $query->whereNull('dispatch_lease_id');
            }

            $query->update([
                'failed_at' => now(),
                'last_error' => $exception?->getMessage() ?: 'Telegram update processing failed after retries.',
                'dispatch_claimed_at' => null,
                'dispatch_lease_id' => null,
                'updated_at' => now(),
            ]);
        });
    }

    private function markProcessed(?TelegramUpdate $record): void
    {
        if ($record === null) {
            return;
        }

        $query = TelegramUpdate::query()
            ->whereKey($record->id)
            ->whereNull('processed_at')
            ->whereNull('failed_at')
            ->whereNull('discarded_at');

        if ($this->dispatchLeaseId !== null) {
            $query->where('dispatch_lease_id', $this->dispatchLeaseId);
        } else {
            $query->whereNull('dispatch_lease_id');
        }

        $query->update([
            'processed_at' => now(),
            'dispatch_claimed_at' => null,
            'dispatch_lease_id' => null,
            'updated_at' => now(),
        ]);
    }

    private function markDiscarded(TelegramUpdate $record, string $reason): void
    {
        $record->forceFill([
            'processed_at' => now(),
            'discarded_at' => now(),
            'last_error' => $reason,
            'dispatch_claimed_at' => null,
            'dispatch_lease_id' => null,
        ])->save();
    }
}
