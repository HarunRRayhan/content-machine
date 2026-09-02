<?php

namespace App\Actions\Telegram;

use App\Data\Telegram\ConnectTelegramBotData;
use App\Models\TelegramBotConfig;
use App\Models\Workspace;
use App\Support\Telegram\TelegramBotCommands;
use App\Support\Telegram\TelegramBotIdentityLock;
use App\Support\Telegram\TelegramClientContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Validates a bot token, durably records the candidate connection, then lets
 * CompleteTelegramBotConnectionAction perform Telegram I/O outside a database
 * transaction. If the process dies at either external boundary, the pending
 * operation remains available to the recovery command.
 */
class ConnectTelegramBotAction
{
    public function __construct(
        private readonly TelegramClientContract $client,
    ) {}

    public function handle(Workspace $workspace, ConnectTelegramBotData $data): TelegramBotConfig
    {
        $getMeResult = $this->client->getMe($data->botToken);

        if (! $getMeResult->successful || $getMeResult->username === null) {
            throw new RuntimeException((string) $getMeResult->error);
        }

        $identityLock = TelegramBotIdentityLock::forWorkspace($workspace->id);
        $identityLock->block(30);

        try {
            $configId = DB::transaction(function () use ($workspace, $data, $getMeResult): int {
                Workspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();

                $config = TelegramBotConfig::query()
                    ->where('workspace_id', $workspace->id)
                    ->lockForUpdate()
                    ->first();

                if ($config?->connection_operation !== null) {
                    throw new RuntimeException(
                        'A Telegram connection operation is already in progress. Retry after recovery completes.',
                    );
                }

                $config ??= new TelegramBotConfig([
                    'workspace_id' => $workspace->id,
                ]);

                $previousToken = $config->bot_token;
                $rotateIdentity = $config->webhook_secret === null
                    || $previousToken === null
                    || $previousToken !== $data->botToken;
                $operationId = (string) Str::uuid();

                $config->forceFill([
                    'workspace_id' => $workspace->id,
                    'connection_operation' => TelegramBotConfig::CONNECTING,
                    'connection_operation_id' => $operationId,
                    'connection_operation_token' => $data->botToken,
                    'connection_operation_username' => $getMeResult->username,
                    'connection_operation_secret' => $rotateIdentity
                        ? Str::random(40)
                        : $config->webhook_secret,
                    'connection_operation_slug' => $rotateIdentity
                        ? Str::random(40)
                        : $config->webhook_slug,
                    'connection_operation_generation' => $rotateIdentity
                        ? (string) Str::uuid()
                        : $config->webhook_generation,
                    'connection_cleanup_token' => null,
                    'connection_operation_error' => null,
                    'connection_operation_started_at' => now(),
                ])->save();

                return $config->id;
            });

            $completion = new CompleteTelegramBotConnectionAction($this->client);
            $completed = $completion->handle($configId);
            $config = TelegramBotConfig::query()->whereKey($configId)->first();

            $cleanupPending = $config?->connection_operation === TelegramBotConfig::CLEANING_UP;

            if ((! $completed && ! $cleanupPending) || $config === null || ! $config->isConnected()) {
                $error = $completion->lastError()
                    ?? $config->connection_operation_error
                    ?? 'Telegram rejected the connection operation.';

                throw new RuntimeException($error);
            }

            try {
                $this->client->setMyCommands($data->botToken, TelegramBotCommands::LIST);
            } catch (Throwable $exception) {
                // The command menu is optional; the connected bot still
                // accepts these commands as ordinary messages.
                report($exception);
            }

            return $config->fresh() ?? $config;
        } finally {
            $identityLock->release();
        }
    }
}
