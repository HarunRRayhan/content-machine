<?php

namespace App\Actions\Telegram;

use App\Jobs\SendTelegramOutboundMessageJob;
use App\Models\TelegramBotConfig;
use App\Models\TelegramOutboundMessage;
use App\Support\Telegram\TelegramClientContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SendTelegramOutboundMessageAction
{
    public function __construct(
        private readonly TelegramClientContract $client,
    ) {}

    public function handle(int $messageId, ?string $dispatchLeaseId = null): void
    {
        // The job middleware serializes queue deliveries. The row status and
        // dispatch lease are the fence for direct calls and recovery races.
        $this->sendChunks($messageId, $dispatchLeaseId);
    }

    private function sendChunks(int $messageId, ?string $dispatchLeaseId): void
    {
        $claim = $this->claimChunk($messageId, $dispatchLeaseId);

        if ($claim === null) {
            return;
        }

        try {
            $result = $this->client->sendMessage(
                $claim['bot_token'],
                $claim['chat_id'],
                $claim['text'],
            );
        } catch (Throwable $exception) {
            $this->markUncertain(
                $messageId,
                $dispatchLeaseId,
                $exception->getMessage() !== ''
                    ? $exception->getMessage()
                    : 'Could not reach Telegram to send the reply.',
            );

            throw $exception;
        }

        if (! $result->successful) {
            $error = $result->error ?? 'Telegram rejected the message.';

            if ($result->outcomeUnknown) {
                $this->markUncertain($messageId, $dispatchLeaseId, $error);

                return;
            }

            $rateLimited = $result->status === 429 || $result->retryAfterSeconds !== null;
            $retryAfter = $rateLimited ? max(1, $result->retryAfterSeconds ?? 60) : null;
            $this->markRetryableFailure($messageId, $dispatchLeaseId, $error, $retryAfter);

            if ($retryAfter !== null) {
                return;
            }

            throw new RuntimeException($error);
        }

        $outcome = $this->completeChunk($messageId, $dispatchLeaseId);

        if ($outcome !== null
            && $outcome['next_message_id'] !== null
            && $outcome['next_lease_id'] !== null
        ) {
            try {
                SendTelegramOutboundMessageJob::dispatch(
                    $outcome['next_message_id'],
                    $outcome['next_lease_id'],
                )->afterCommit();
            } catch (Throwable $exception) {
                // The progress checkpoint is already committed. The scheduled
                // outbox drain can enqueue the next chunk if this dispatch is
                // unavailable.
                report($exception);
            }
        }
    }

    /**
     * Move one chunk to `sending` before calling Telegram. If the process dies
     * after Telegram accepts the request, the durable state is then
     * distinguishable from a pending message and recovery can fail closed.
     *
     * @return array{bot_token: string, chat_id: int, text: string}|null
     */
    private function claimChunk(int $messageId, ?string $dispatchLeaseId): ?array
    {
        return DB::transaction(function () use ($messageId, $dispatchLeaseId): ?array {
            $messageReference = TelegramOutboundMessage::query()
                ->whereKey($messageId)
                ->first(['telegram_bot_config_id']);

            if ($messageReference === null) {
                return null;
            }

            // Rotation and disconnect lock the config before changing any
            // outbound row. Acquire the same lock order here.
            $config = TelegramBotConfig::query()
                ->whereKey($messageReference->telegram_bot_config_id)
                ->lockForUpdate()
                ->first();
            $message = TelegramOutboundMessage::query()
                ->whereKey($messageId)
                ->lockForUpdate()
                ->first();

            if ($message === null || $message->status !== TelegramOutboundMessage::PENDING) {
                return null;
            }

            $staleAt = now()->subSeconds(SendTelegramOutboundMessageJob::DISPATCH_LEASE_SECONDS);
            $claimExpired = $message->dispatch_claimed_at === null
                || $message->dispatch_claimed_at->lessThanOrEqualTo($staleAt);

            if ($dispatchLeaseId !== null) {
                if ($message->dispatch_lease_id !== $dispatchLeaseId
                    || $message->dispatch_claimed_at === null
                    || $claimExpired
                ) {
                    return null;
                }
            } elseif ($message->dispatch_lease_id !== null && ! $claimExpired) {
                return null;
            }

            if ($message->next_attempt_at !== null && $message->next_attempt_at->isFuture()) {
                $message->forceFill([
                    'dispatch_claimed_at' => null,
                    'dispatch_lease_id' => null,
                ])->save();

                return null;
            }

            if ($config === null) {
                $message->forceFill([
                    'status' => TelegramOutboundMessage::DISCARDED,
                    'discarded_at' => now(),
                    'dispatch_claimed_at' => null,
                    'dispatch_lease_id' => null,
                    'last_error' => 'The Telegram bot configuration no longer exists.',
                ])->save();

                return null;
            }

            if ($message->webhook_generation !== null
                && $config->webhook_generation !== $message->webhook_generation
            ) {
                $message->forceFill([
                    'status' => TelegramOutboundMessage::DISCARDED,
                    'discarded_at' => now(),
                    'dispatch_claimed_at' => null,
                    'dispatch_lease_id' => null,
                    'last_error' => 'The Telegram bot connection changed before this message was sent.',
                ])->save();

                return null;
            }

            if ($message->webhook_generation === null && $config->webhook_generation !== null) {
                // Rows created before generation tracking are adopted only
                // while the config lock is held.
                $message->forceFill([
                    'webhook_generation' => $config->webhook_generation,
                ])->save();
            }

            if ($dispatchLeaseId === null && $message->dispatch_lease_id !== null) {
                // An old direct-dispatch payload may arrive after a recovery
                // claim expired. Adopt the row under the message lock instead
                // of leaving a stale lease that cannot be finalized by this
                // payload.
                $message->forceFill([
                    'dispatch_claimed_at' => null,
                    'dispatch_lease_id' => null,
                ])->save();
            }

            /** @var mixed $chunksValue */
            $chunksValue = $message->chunks;
            $chunks = $chunksValue;
            $index = $message->next_chunk;

            if (! is_array($chunks) || $chunks === [] || ! isset($chunks[$index]) || ! is_string($chunks[$index])) {
                $message->forceFill([
                    'status' => TelegramOutboundMessage::FAILED,
                    'failed_at' => now(),
                    'dispatch_claimed_at' => null,
                    'dispatch_lease_id' => null,
                    'last_error' => 'The stored Telegram message chunks are invalid.',
                ])->save();

                return null;
            }

            if ($config->bot_token === null) {
                $message->forceFill([
                    'status' => TelegramOutboundMessage::DISCARDED,
                    'discarded_at' => now(),
                    'last_error' => 'The Telegram bot was disconnected before this message was sent.',
                    'next_attempt_at' => null,
                    'dispatch_claimed_at' => null,
                    'dispatch_lease_id' => null,
                ])->save();

                return null;
            }

            $message->forceFill([
                'status' => TelegramOutboundMessage::SENDING,
                'attempts' => $message->attempts + 1,
                'last_attempt_at' => now(),
                'last_error' => null,
                'failed_at' => null,
                'dispatch_claimed_at' => now(),
            ])->save();

            return [
                'bot_token' => (string) $config->bot_token,
                'chat_id' => $message->chat_id,
                'text' => $chunks[$index],
            ];
        });
    }

    /**
     * @return array{next_message_id: int|null, next_lease_id: string|null}|null
     */
    private function completeChunk(int $messageId, ?string $dispatchLeaseId): ?array
    {
        return DB::transaction(function () use ($messageId, $dispatchLeaseId): ?array {
            $messageReference = TelegramOutboundMessage::query()
                ->whereKey($messageId)
                ->first(['telegram_bot_config_id']);

            if ($messageReference === null) {
                return null;
            }

            $config = TelegramBotConfig::query()
                ->whereKey($messageReference->telegram_bot_config_id)
                ->lockForUpdate()
                ->first();

            $message = TelegramOutboundMessage::query()
                ->whereKey($messageId)
                ->lockForUpdate()
                ->first();

            if ($message === null
                || $message->status !== TelegramOutboundMessage::SENDING
                || ! $this->ownsDispatchClaim($message, $dispatchLeaseId)
            ) {
                return null;
            }

            if ($config === null
                || $message->webhook_generation !== $config->webhook_generation
                || $config->bot_token === null
            ) {
                $message->forceFill([
                    'status' => TelegramOutboundMessage::UNCERTAIN,
                    'failed_at' => now(),
                    'last_error' => 'Telegram delivery outcome is uncertain because the bot connection changed during sending.',
                    'dispatch_claimed_at' => null,
                    'dispatch_lease_id' => null,
                    'next_attempt_at' => null,
                ])->save();

                return null;
            }

            $nextChunk = $message->next_chunk + 1;
            /** @var mixed $chunks */
            $chunks = $message->chunks;
            if (! is_array($chunks) || $nextChunk > count($chunks)) {
                $this->markInvalidMessage($message);

                return null;
            }

            $complete = $nextChunk >= count($chunks);
            $nextLeaseId = $complete ? null : (string) Str::uuid();
            $message->forceFill([
                'next_chunk' => $nextChunk,
                'attempts' => 0,
                'last_error' => null,
                'next_attempt_at' => null,
                'status' => $complete ? TelegramOutboundMessage::SENT : TelegramOutboundMessage::PENDING,
                'sent_at' => $complete ? now() : null,
                'dispatch_claimed_at' => $complete ? null : now(),
                'dispatch_lease_id' => $nextLeaseId,
            ])->save();

            return [
                'next_message_id' => $complete ? null : $message->id,
                'next_lease_id' => $nextLeaseId,
            ];
        });
    }

    private function markRetryableFailure(
        int $messageId,
        ?string $dispatchLeaseId,
        string $error,
        ?int $retryAfter,
    ): void {
        DB::transaction(function () use ($messageId, $dispatchLeaseId, $error, $retryAfter): void {
            $message = TelegramOutboundMessage::query()
                ->whereKey($messageId)
                ->lockForUpdate()
                ->first();

            if ($message === null
                || $message->status !== TelegramOutboundMessage::SENDING
                || ! $this->ownsDispatchClaim($message, $dispatchLeaseId)
            ) {
                return;
            }

            $message->forceFill([
                'status' => TelegramOutboundMessage::PENDING,
                'last_error' => $error,
                'next_attempt_at' => now()->addSeconds($retryAfter ?? 60),
                'dispatch_claimed_at' => $retryAfter === null ? $message->dispatch_claimed_at : null,
                'dispatch_lease_id' => $retryAfter === null ? $message->dispatch_lease_id : null,
            ])->save();
        });
    }

    private function markUncertain(int $messageId, ?string $dispatchLeaseId, string $error): void
    {
        DB::transaction(function () use ($messageId, $dispatchLeaseId, $error): void {
            $messageReference = TelegramOutboundMessage::query()
                ->whereKey($messageId)
                ->first(['telegram_bot_config_id']);

            if ($messageReference === null) {
                return;
            }

            TelegramBotConfig::query()
                ->whereKey($messageReference->telegram_bot_config_id)
                ->lockForUpdate()
                ->first();

            $message = TelegramOutboundMessage::query()
                ->whereKey($messageId)
                ->lockForUpdate()
                ->first();

            if ($message === null
                || $message->status !== TelegramOutboundMessage::SENDING
                || ! $this->ownsDispatchClaim($message, $dispatchLeaseId)
            ) {
                return;
            }

            $message->forceFill([
                'status' => TelegramOutboundMessage::UNCERTAIN,
                'failed_at' => now(),
                'last_error' => 'Telegram delivery outcome is uncertain. Verify the chat before retrying. '.$error,
                'dispatch_claimed_at' => null,
                'dispatch_lease_id' => null,
                'next_attempt_at' => null,
            ])->save();
        });
    }

    private function ownsDispatchClaim(TelegramOutboundMessage $message, ?string $dispatchLeaseId): bool
    {
        return $dispatchLeaseId === null
            ? $message->dispatch_lease_id === null
            : $message->dispatch_lease_id === $dispatchLeaseId;
    }

    private function markInvalidMessage(TelegramOutboundMessage $message): void
    {
        $message->forceFill([
            'status' => TelegramOutboundMessage::FAILED,
            'failed_at' => now(),
            'dispatch_claimed_at' => null,
            'dispatch_lease_id' => null,
            'last_error' => 'The stored Telegram message chunks are invalid.',
        ])->save();
    }
}
