<?php

namespace App\Jobs;

use App\Actions\Telegram\HandleTelegramUpdateAction;
use App\Models\TelegramBotConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Text, link, and command updates use the supervised default queue. Photo and
 * voice updates stay on scratchpad because their media files live on cm-web.
 */
class ProcessTelegramUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $update
     */
    public function __construct(
        public readonly int $telegramBotConfigId,
        public readonly array $update,
    ) {
        $this->onQueue('default');

        $message = $update['message'] ?? null;

        if (is_array($message) && (isset($message['photo']) || isset($message['voice']))) {
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

        // Disconnected between the webhook accepting this update and the
        // worker picking it up: nothing to do, and definitely nothing to
        // reply with (the token that would authenticate a reply is gone).
        if ($config === null || ! $config->isConnected()) {
            return;
        }

        $action->handle($config, $this->update);
    }
}
