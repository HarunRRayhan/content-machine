<?php

namespace App\Actions\Telegram;

use App\Models\TelegramBotConfig;
use App\Models\Workspace;
use App\Support\Telegram\TelegramClientContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Disables the bot (TelegramBotConfig::isConnected() becomes false)
 * without discarding webhook_secret/webhook_slug, so a later reconnect
 * doesn't change the workspace's webhook URL. Telegram's deleteWebhook is
 * called best-effort: a disconnect always succeeds locally even if
 * Telegram is briefly unreachable, since the user asked to turn this off
 * and shouldn't be blocked by Telegram's own API. Existing TelegramBotLink
 * rows are left untouched: reconnecting the same workspace's bot later
 * shouldn't force every already-linked member to re-link.
 */
class DisconnectTelegramBotAction
{
    public function __construct(
        private readonly TelegramClientContract $client,
    ) {}

    public function handle(TelegramBotConfig $config): void
    {
        DB::transaction(function () use ($config): void {
            // Use the same parent lock as connect. A config row does not
            // exist on a partially deleted workspace, so the parent is the
            // stable serialization point for both operations.
            Workspace::query()->whereKey($config->workspace_id)->lockForUpdate()->firstOrFail();

            $locked = TelegramBotConfig::query()
                ->whereKey($config->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return;
            }

            if ($locked->bot_token !== null) {
                try {
                    $this->client->deleteWebhook($locked->bot_token);
                } catch (Throwable $exception) {
                    // Disconnect is a local safety decision. Telegram may be
                    // temporarily unavailable and must not keep the bot live
                    // in Content Machine.
                    report($exception);
                }
            }

            $locked->forceFill([
                'bot_token' => null,
                'bot_username' => null,
                'connected_at' => null,
                // Invalidate updates accepted by the previous connection,
                // including ones that survive a quick disconnect/reconnect.
                'webhook_generation' => (string) Str::uuid(),
            ])->save();
        });
    }
}
