<?php

namespace App\Actions\Telegram;

use App\Models\TelegramBotConfig;
use App\Models\Workspace;
use App\Support\Telegram\TelegramBotIdentityLock;
use App\Support\Telegram\TelegramClientContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Disables the bot locally in one short transaction, then removes the remote
 * webhook. A failed or interrupted delete stays durably retryable while the
 * local webhook handler remains disabled.
 */
class DisconnectTelegramBotAction
{
    public function __construct(
        private readonly TelegramClientContract $client,
    ) {}

    public function handle(TelegramBotConfig $config): void
    {
        $identityLock = TelegramBotIdentityLock::forWorkspace($config->workspace_id);
        $identityLock->block(30);

        try {
            $configId = DB::transaction(function () use ($config): ?int {
                $reference = TelegramBotConfig::query()
                    ->whereKey($config->id)
                    ->first(['workspace_id']);

                if ($reference === null) {
                    return null;
                }

                Workspace::query()->whereKey($reference->workspace_id)->lockForUpdate()->first();

                $locked = TelegramBotConfig::query()
                    ->whereKey($config->id)
                    ->lockForUpdate()
                    ->first();

                if ($locked === null || $locked->bot_token === null) {
                    return null;
                }

                if ($locked->connection_operation !== null) {
                    throw new \RuntimeException(
                        'A Telegram connection operation is already in progress. Retry after recovery completes.',
                    );
                }

                $now = now();
                $locked->forceFill([
                    'connection_operation' => TelegramBotConfig::DISCONNECTING,
                    'connection_operation_id' => (string) Str::uuid(),
                    'connection_operation_token' => $locked->bot_token,
                    'connection_operation_username' => null,
                    'connection_operation_secret' => null,
                    'connection_operation_slug' => null,
                    'connection_operation_generation' => null,
                    'connection_cleanup_token' => null,
                    'connection_operation_error' => null,
                    'connection_operation_started_at' => $now,
                    'bot_token' => null,
                    'bot_username' => null,
                    'connected_at' => null,
                    // Invalidate updates accepted by the previous connection,
                    // including ones that survive a quick disconnect/reconnect.
                    'webhook_generation' => (string) Str::uuid(),
                ]);

                (new CompleteTelegramBotConnectionAction($this->client))->retireOpenWork(
                    $locked,
                    'The Telegram bot was disconnected before this work was completed.',
                );
                $locked->save();

                return $locked->id;
            });

            if ($configId === null) {
                return;
            }

            try {
                (new CompleteTelegramBotConnectionAction($this->client))->handle($configId);
            } catch (Throwable $exception) {
                // The local disable is already committed. The recovery
                // command will retry the remote delete without re-enabling it.
                report($exception);
            }
        } finally {
            $identityLock->release();
        }
    }
}
