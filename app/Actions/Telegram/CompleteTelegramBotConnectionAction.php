<?php

namespace App\Actions\Telegram;

use App\Models\TelegramBotConfig;
use App\Models\TelegramOutboundMessage;
use App\Models\TelegramPostRequest;
use App\Models\TelegramUpdate;
use App\Models\Workspace;
use App\Support\Telegram\TelegramClientContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Completes a Telegram connection operation after its intent has been
 * committed. Telegram calls happen outside database transactions; a worker
 * or deploy can therefore die without rolling back the durable operation.
 */
class CompleteTelegramBotConnectionAction
{
    private ?string $lastError = null;

    public function __construct(
        private readonly TelegramClientContract $client,
    ) {}

    public function handle(int $configId): bool
    {
        $config = TelegramBotConfig::query()->whereKey($configId)->first();

        if ($config === null || $config->connection_operation === null) {
            return true;
        }

        return match ($config->connection_operation) {
            TelegramBotConfig::CONNECTING => $this->completeConnecting($config),
            TelegramBotConfig::CLEANING_UP => $this->completeCleanup($config),
            TelegramBotConfig::DISCONNECTING => $this->completeDisconnecting($config),
            default => $this->markUnknownOperation($config),
        };
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Retire work that belongs to the identity being replaced. This method is
     * called while the caller already holds the workspace/config transaction.
     */
    public function retireOpenWork(TelegramBotConfig $config, string $reason): void
    {
        $now = now();

        TelegramUpdate::query()
            ->where('telegram_bot_config_id', $config->id)
            ->whereNull('processed_at')
            ->whereNull('failed_at')
            ->whereNull('discarded_at')
            ->update([
                'processed_at' => $now,
                'discarded_at' => $now,
                'last_error' => $reason,
                'dispatch_claimed_at' => null,
                'dispatch_lease_id' => null,
                'updated_at' => $now,
            ]);

        TelegramPostRequest::query()
            ->where('telegram_bot_config_id', $config->id)
            ->whereIn('state', TelegramPostRequest::ACTIVE_STATES)
            ->update([
                'state' => TelegramPostRequest::CANCELLED,
                'cancelled_at' => $now,
                'error_message' => $reason,
                'updated_at' => $now,
            ]);

        TelegramOutboundMessage::query()
            ->where('telegram_bot_config_id', $config->id)
            ->where('status', TelegramOutboundMessage::PENDING)
            ->update([
                'status' => TelegramOutboundMessage::DISCARDED,
                'discarded_at' => $now,
                'dispatch_claimed_at' => null,
                'dispatch_lease_id' => null,
                'last_error' => $reason,
                'updated_at' => $now,
            ]);

        // A sending row may already have crossed Telegram's boundary. Keep it
        // for explicit reconciliation instead of silently treating it as
        // undelivered.
        TelegramOutboundMessage::query()
            ->where('telegram_bot_config_id', $config->id)
            ->where('status', TelegramOutboundMessage::SENDING)
            ->update([
                'status' => TelegramOutboundMessage::UNCERTAIN,
                'failed_at' => $now,
                'next_attempt_at' => null,
                'dispatch_claimed_at' => null,
                'dispatch_lease_id' => null,
                'last_error' => $reason.' Delivery may already have reached Telegram; verify the chat before retrying.',
                'updated_at' => $now,
            ]);
    }

    private function completeConnecting(TelegramBotConfig $config): bool
    {
        if (! is_string($config->connection_operation_id)
            || ! is_string($config->connection_operation_token)
            || ! is_string($config->connection_operation_secret)
            || ! is_string($config->connection_operation_slug)
            || ! is_string($config->connection_operation_generation)
            || ! is_string($config->connection_operation_username)
        ) {
            $this->markOperationError($config, 'The durable Telegram connect operation is incomplete.');

            return false;
        }

        $operationId = $config->connection_operation_id;
        $webhookUrl = URL::route('telegram.webhook', ['slug' => $config->connection_operation_slug]);

        try {
            $result = $this->client->setWebhook(
                $config->connection_operation_token,
                $webhookUrl,
                $config->connection_operation_secret,
            );
        } catch (Throwable $exception) {
            $this->markOperationError($config, $exception->getMessage() ?: 'Could not register the Telegram webhook.');

            throw $exception;
        }

        if (! $result->successful) {
            $error = $result->error ?? 'Telegram rejected the webhook registration.';
            $this->markConnectFailure($config, $operationId, $error, $result->outcomeUnknown);

            return false;
        }

        $promotion = $this->promoteConnectedConfig($config->id, $operationId);

        if (! $promotion['promoted']) {
            $this->lastError ??= 'The Telegram connection operation changed before it could be activated.';

            return false;
        }

        $cleanupToken = $promotion['cleanup_token'];

        if ($cleanupToken !== null) {
            return $this->completeCleanup(TelegramBotConfig::query()->whereKey($config->id)->firstOrFail());
        }

        return true;
    }

    private function completeCleanup(TelegramBotConfig $config): bool
    {
        $operationId = $config->connection_operation_id;
        $token = $config->connection_cleanup_token;

        if (! is_string($operationId)) {
            $this->markOperationError($config, 'The durable Telegram cleanup operation has no operation id.');

            return false;
        }

        if (! is_string($token) || $token === '') {
            $this->clearOperation($config->id, $operationId);

            return true;
        }

        try {
            $result = $this->client->deleteWebhook($token);
        } catch (Throwable $exception) {
            $this->markOperationError($config, $exception->getMessage() ?: 'Could not remove the old Telegram webhook.');

            throw $exception;
        }

        if (! $result->successful) {
            $this->markOperationError(
                $config,
                $result->error ?? 'Telegram rejected the old webhook removal.',
            );

            return false;
        }

        $this->clearOperation($config->id, $operationId);

        return true;
    }

    private function completeDisconnecting(TelegramBotConfig $config): bool
    {
        $operationId = $config->connection_operation_id;
        $token = $config->connection_operation_token;

        if (! is_string($operationId)) {
            $this->markOperationError($config, 'The durable Telegram disconnect operation has no operation id.');

            return false;
        }

        if (! is_string($token) || $token === '') {
            $this->clearOperation($config->id, $operationId);

            return true;
        }

        try {
            $result = $this->client->deleteWebhook($token);
        } catch (Throwable $exception) {
            $this->markOperationError($config, $exception->getMessage() ?: 'Could not remove the Telegram webhook.');

            throw $exception;
        }

        if (! $result->successful) {
            // A failed delete is retained even when Telegram says the request
            // was rejected: the local bot is already disabled, but the remote
            // webhook still needs an explicit retry.
            $this->markOperationError(
                $config,
                $result->error ?? 'Telegram rejected the webhook removal.',
            );

            return false;
        }

        $this->clearOperation($config->id, $operationId);

        return true;
    }

    /**
     * @return array{promoted: bool, cleanup_token: string|null}
     */
    private function promoteConnectedConfig(int $configId, string $operationId): array
    {
        return DB::transaction(function () use ($configId, $operationId): array {
            $config = $this->lockConfig($configId);

            if ($config === null || $config->connection_operation_id !== $operationId) {
                return ['promoted' => false, 'cleanup_token' => null];
            }

            $candidateToken = $config->connection_operation_token;
            $candidateUsername = $config->connection_operation_username;
            $candidateSecret = $config->connection_operation_secret;
            $candidateSlug = $config->connection_operation_slug;
            $candidateGeneration = $config->connection_operation_generation;

            if (! is_string($candidateToken)
                || ! is_string($candidateUsername)
                || ! is_string($candidateSecret)
                || ! is_string($candidateSlug)
                || ! is_string($candidateGeneration)
            ) {
                throw new \RuntimeException('The durable Telegram connect operation is incomplete.');
            }

            $previousToken = $config->bot_token;
            $identityChanged = $config->webhook_generation !== $candidateGeneration;
            $cleanupToken = is_string($previousToken) && $previousToken !== $candidateToken
                ? $previousToken
                : null;

            if ($identityChanged) {
                $this->retireOpenWork(
                    $config,
                    'The Telegram bot connection changed before this work was completed.',
                );
            }

            $config->forceFill([
                'bot_token' => $candidateToken,
                'bot_username' => $candidateUsername,
                'connected_at' => now(),
                'webhook_secret' => $candidateSecret,
                'webhook_slug' => $candidateSlug,
                'webhook_generation' => $candidateGeneration,
                'connection_operation' => $cleanupToken !== null ? TelegramBotConfig::CLEANING_UP : null,
                'connection_cleanup_token' => $cleanupToken,
                'connection_operation_token' => null,
                'connection_operation_username' => null,
                'connection_operation_secret' => null,
                'connection_operation_slug' => null,
                'connection_operation_generation' => null,
                'connection_operation_error' => null,
                'connection_operation_started_at' => $cleanupToken !== null
                    ? $config->connection_operation_started_at
                    : null,
            ]);

            if ($cleanupToken === null) {
                $config->connection_operation_id = null;
            }

            $config->save();

            return ['promoted' => true, 'cleanup_token' => $cleanupToken];
        });
    }

    private function markConnectFailure(
        TelegramBotConfig $config,
        string $operationId,
        string $error,
        bool $retainOperation,
    ): void {
        $this->lastError = $error;

        DB::transaction(function () use ($config, $operationId, $error, $retainOperation): void {
            $locked = $this->lockConfig($config->id);

            if ($locked === null || $locked->connection_operation_id !== $operationId) {
                return;
            }

            if ($retainOperation) {
                $locked->forceFill([
                    'connection_operation_error' => $error,
                ])->save();

                return;
            }

            $deletePlaceholder = $locked->bot_token === null
                && $locked->webhook_secret === null
                && $locked->webhook_slug === null;

            if ($deletePlaceholder) {
                $locked->delete();

                return;
            }

            $this->clearOperationFields($locked);
            $locked->save();
        });
    }

    private function markOperationError(TelegramBotConfig $config, string $error): void
    {
        $this->lastError = $error;

        $operationId = $config->connection_operation_id;
        if (! is_string($operationId)) {
            return;
        }

        DB::transaction(function () use ($config, $operationId, $error): void {
            $locked = $this->lockConfig($config->id);

            if ($locked === null || $locked->connection_operation_id !== $operationId) {
                return;
            }

            $locked->forceFill([
                'connection_operation_error' => $error,
            ])->save();
        });
    }

    private function clearOperation(int $configId, string $operationId): void
    {
        DB::transaction(function () use ($configId, $operationId): void {
            $config = $this->lockConfig($configId);

            if ($config === null || $config->connection_operation_id !== $operationId) {
                return;
            }

            $this->clearOperationFields($config);
            $config->save();
        });
    }

    private function clearOperationFields(TelegramBotConfig $config): void
    {
        $config->forceFill([
            'connection_operation' => null,
            'connection_operation_id' => null,
            'connection_operation_token' => null,
            'connection_operation_username' => null,
            'connection_operation_secret' => null,
            'connection_operation_slug' => null,
            'connection_operation_generation' => null,
            'connection_cleanup_token' => null,
            'connection_operation_error' => null,
            'connection_operation_started_at' => null,
        ]);
    }

    private function markUnknownOperation(TelegramBotConfig $config): bool
    {
        $this->markOperationError($config, 'The Telegram connection operation type is not recognized.');

        return false;
    }

    private function lockConfig(int $configId): ?TelegramBotConfig
    {
        $reference = TelegramBotConfig::query()
            ->whereKey($configId)
            ->first(['workspace_id']);

        if ($reference === null) {
            return null;
        }

        Workspace::query()
            ->whereKey($reference->workspace_id)
            ->lockForUpdate()
            ->first();

        return TelegramBotConfig::query()
            ->whereKey($configId)
            ->lockForUpdate()
            ->first();
    }
}
