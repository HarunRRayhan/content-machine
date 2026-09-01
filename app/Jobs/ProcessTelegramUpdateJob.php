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

/**
 * Text, link, and command updates use the supervised default queue. Photo,
 * voice, and audio updates stay on scratchpad because their media files live
 * on cm-web.
 */
class ProcessTelegramUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const OVERLAP_EXPIRES_AFTER_SECONDS = 960;

    /**
     * Old queued payloads do not contain generation-aware webhook fields.
     * Keep the new field explicitly initialized so those payloads deserialize
     * without an uninitialized typed-property error.
     */
    public ?string $webhookGeneration = null;

    /**
     * @param  array<string, mixed>  $update
     */
    public function __construct(
        public readonly int $telegramBotConfigId,
        public readonly array $update,
        ?string $webhookGeneration = null,
    ) {
        $this->webhookGeneration = $webhookGeneration;
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
        $config = TelegramBotConfig::find($this->telegramBotConfigId);

        $updateId = $this->update['update_id'] ?? null;
        $record = null;
        if (is_int($updateId) || (is_string($updateId) && ctype_digit($updateId))) {
            $recordQuery = TelegramUpdate::query()
                ->where('telegram_bot_config_id', $this->telegramBotConfigId)
                ->where('update_id', (int) $updateId);

            if ($this->webhookGeneration !== null) {
                $recordQuery->where('webhook_generation', $this->webhookGeneration);
            }

            $record = $recordQuery->first();
        }

        if ($record?->processed_at !== null) {
            return;
        }

        // Disconnected between the webhook accepting this update and the
        // worker picking it up: nothing to do, and definitely nothing to
        // reply with (the token that would authenticate a reply is gone).
        if ($config === null
            || ! $config->isConnected()
            || ($this->webhookGeneration !== null
                && $config->webhook_generation !== $this->webhookGeneration)
            || ($record !== null
                && $record->webhook_generation !== null
                && $record->webhook_generation !== $config->webhook_generation)
        ) {
            $this->markProcessed($record);

            return;
        }

        $payload = is_array($record?->payload) ? $record->payload : $this->update;
        $action->handle($config, $payload);
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

    private function markProcessed(?TelegramUpdate $record): void
    {
        $record?->forceFill(['processed_at' => now()])->save();
    }
}
