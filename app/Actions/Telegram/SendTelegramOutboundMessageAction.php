<?php

namespace App\Actions\Telegram;

use App\Jobs\SendTelegramOutboundMessageJob;
use App\Models\TelegramBotConfig;
use App\Models\TelegramOutboundMessage;
use App\Support\Telegram\TelegramClientContract;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SendTelegramOutboundMessageAction
{
    public const LOCK_SECONDS = 60;

    public function __construct(
        private readonly TelegramClientContract $client,
    ) {}

    public function handle(int $messageId, ?string $dispatchLeaseId = null): void
    {
        $lock = Cache::lock('telegram-outbound-message:'.$messageId, self::LOCK_SECONDS);

        if (! $lock->get()) {
            return;
        }

        try {
            $this->sendChunks($messageId, $dispatchLeaseId);
        } finally {
            $lock->release();
        }
    }

    private function sendChunks(int $messageId, ?string $dispatchLeaseId): void
    {
        /** @var array{next_message_id: int|null, next_lease_id: string|null, error: string|null, retry_after: int|null, exception: Throwable|null}|null $outcome */
        $outcome = DB::transaction(function () use ($messageId, $dispatchLeaseId): ?array {
            $messageReference = TelegramOutboundMessage::query()
                ->whereKey($messageId)
                ->first(['telegram_bot_config_id']);

            if ($messageReference === null) {
                return null;
            }

            // Rotation and disconnect lock the config before changing any
            // outbound row. Acquire the same lock order here so delivery cannot
            // deadlock with either operation while Telegram is being called.
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
                // A recovery job must never send after its durable lease has
                // expired. A later recovery run owns the right to retry it.
                if ($message->dispatch_lease_id !== $dispatchLeaseId
                    || $message->dispatch_claimed_at === null
                    || $claimExpired
                ) {
                    return null;
                }
            } elseif ($message->dispatch_lease_id !== null && ! $claimExpired) {
                // A recovery job owns this row. A direct job must not steal it.
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
                // while the config lock is held. Rotation/disconnect marks
                // pending legacy rows discarded before changing this value.
                $message->forceFill([
                    'webhook_generation' => $config->webhook_generation,
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
                'attempts' => $message->attempts + 1,
                'last_attempt_at' => now(),
            ])->save();

            try {
                $result = $this->client->sendMessage(
                    (string) $config->bot_token,
                    $message->chat_id,
                    $chunks[$index],
                );
            } catch (Throwable $exception) {
                $message->forceFill([
                    'last_error' => $exception->getMessage() !== ''
                        ? $exception->getMessage()
                        : 'Could not reach Telegram to send the reply.',
                    'next_attempt_at' => now()->addMinute(),
                ])->save();

                return [
                    'next_message_id' => null,
                    'next_lease_id' => null,
                    'error' => null,
                    'retry_after' => null,
                    'exception' => $exception,
                ];
            }

            if (! $result->successful) {
                $error = $result->error ?? 'Telegram rejected the message.';
                $rateLimited = $result->status === 429 || $result->retryAfterSeconds !== null;
                $retryAfter = $rateLimited ? max(1, $result->retryAfterSeconds ?? 60) : null;

                $message->forceFill([
                    'last_error' => $error,
                    'next_attempt_at' => now()->addSeconds($retryAfter ?? 60),
                    'dispatch_claimed_at' => $rateLimited ? null : $message->dispatch_claimed_at,
                    'dispatch_lease_id' => $rateLimited ? null : $message->dispatch_lease_id,
                ])->save();

                return [
                    'next_message_id' => null,
                    'next_lease_id' => null,
                    'error' => $error,
                    'retry_after' => $retryAfter,
                    'exception' => null,
                ];
            }

            $nextChunk = $message->next_chunk + 1;
            $chunkCount = count($chunks);
            $complete = $nextChunk >= $chunkCount;
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
                'error' => null,
                'retry_after' => null,
                'exception' => null,
            ];
        });

        if ($outcome === null) {
            return;
        }

        if ($outcome['exception'] !== null) {
            throw $outcome['exception'];
        }

        if ($outcome['error'] !== null) {
            if ($outcome['retry_after'] !== null) {
                return;
            }

            throw new RuntimeException($outcome['error']);
        }

        if ($outcome['next_message_id'] !== null && $outcome['next_lease_id'] !== null) {
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
}
