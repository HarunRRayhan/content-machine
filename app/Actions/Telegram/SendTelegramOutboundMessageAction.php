<?php

namespace App\Actions\Telegram;

use App\Jobs\SendTelegramOutboundMessageJob;
use App\Models\TelegramBotConfig;
use App\Models\TelegramOutboundMessage;
use App\Support\Telegram\TelegramBotIdentityLock;
use App\Support\Telegram\TelegramClientContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class SendTelegramOutboundMessageAction
{
    public function __construct(
        private readonly TelegramClientContract $client,
    ) {}

    public function handle(int $messageId, ?string $dispatchLeaseId = null): void
    {
        $this->sendChunks($messageId, $dispatchLeaseId);
    }

    private function sendChunks(int $messageId, ?string $dispatchLeaseId): void
    {
        $reference = TelegramOutboundMessage::query()
            ->whereKey($messageId)
            ->first(['telegram_bot_config_id']);

        if ($reference === null) {
            return;
        }

        $workspaceId = TelegramBotConfig::query()
            ->whereKey($reference->telegram_bot_config_id)
            ->value('workspace_id');

        if (! is_int($workspaceId) && ! (is_string($workspaceId) && ctype_digit($workspaceId))) {
            return;
        }

        $identityLock = TelegramBotIdentityLock::forWorkspace((int) $workspaceId);

        // A recovery-dispatched job already owns a short-lived outbox lease.
        // Do not spend the whole job timeout waiting behind a connection
        // operation. The scheduler will retry after the lease is released.
        if (! $identityLock->get()) {
            $this->releaseDispatchLease($messageId, $dispatchLeaseId);

            return;
        }

        try {
            // The sending marker is committed before Telegram is called. If
            // the process dies after Telegram accepts the request, the stale
            // row remains distinguishable and recovery can fail closed.
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
                if ($rateLimited) {
                    $this->markRetryableFailure(
                        $messageId,
                        $dispatchLeaseId,
                        $error,
                        max(1, $result->retryAfterSeconds ?? 60),
                    );

                    return;
                }

                $this->markFailed($messageId, $dispatchLeaseId, $error);

                return;
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
                    );
                } catch (Throwable $exception) {
                    // The progress checkpoint is already committed. The
                    // scheduled outbox drain can enqueue the next chunk if
                    // this dispatch is unavailable.
                    report($exception);
                }
            }
        } finally {
            $identityLock->release();
        }
    }

    /**
     * Move one chunk to `sending` in a short transaction before calling
     * Telegram. The identity lock serializes this commit with rotation and
     * disconnect without keeping a database transaction open over I/O.
     *
     * @return array{bot_token: string, chat_id: int, text: string}|null
     */
    private function claimChunk(int $messageId, ?string $dispatchLeaseId): ?array
    {
        return DB::transaction(function () use ($messageId, $dispatchLeaseId): ?array {
            $context = $this->lockedMessageContext($messageId);
            if ($context === null) {
                return null;
            }

            $config = $context['config'];
            $message = $context['message'];

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
                $this->discard($message, 'The Telegram bot configuration no longer exists.');

                return null;
            }

            if ($message->webhook_generation !== null
                && $config->webhook_generation !== $message->webhook_generation
            ) {
                $this->discard($message, 'The Telegram bot connection changed before this message was sent.');

                return null;
            }

            if ($message->webhook_generation === null && $config->webhook_generation !== null) {
                // Rows created before generation tracking are adopted only
                // while the config identity lock is held.
                $message->webhook_generation = $config->webhook_generation;
            }

            if ($dispatchLeaseId === null && $message->dispatch_lease_id !== null) {
                // An old queued payload may arrive after its recovery lease
                // expired. Adopt it only after taking the message lock.
                $message->dispatch_claimed_at = null;
                $message->dispatch_lease_id = null;
            }

            /** @var mixed $chunks */
            $chunks = $message->getAttribute('chunks');
            $index = $message->next_chunk;

            if (! is_array($chunks)
                || $chunks === []
                || ! isset($chunks[$index])
                || ! is_string($chunks[$index])
            ) {
                $this->markInvalidMessage($message);

                return null;
            }

            if ($config->bot_token === null) {
                $this->discard($message, 'The Telegram bot was disconnected before this message was sent.');

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
     * @return array{config: TelegramBotConfig|null, message: TelegramOutboundMessage|null}|null
     */
    private function lockedMessageContext(int $messageId): ?array
    {
        $reference = TelegramOutboundMessage::query()
            ->whereKey($messageId)
            ->first(['telegram_bot_config_id']);

        if ($reference === null) {
            return null;
        }

        $configReference = TelegramBotConfig::query()
            ->whereKey($reference->telegram_bot_config_id)
            ->first(['workspace_id']);

        if ($configReference !== null) {
            DB::table('workspaces')
                ->where('id', $configReference->workspace_id)
                ->lockForUpdate()
                ->first();
        }

        $config = TelegramBotConfig::query()
            ->whereKey($reference->telegram_bot_config_id)
            ->lockForUpdate()
            ->first();
        $message = TelegramOutboundMessage::query()
            ->whereKey($messageId)
            ->lockForUpdate()
            ->first();

        return ['config' => $config, 'message' => $message];
    }

    /**
     * @return array{next_message_id: int|null, next_lease_id: string|null}|null
     */
    private function completeChunk(int $messageId, ?string $dispatchLeaseId): ?array
    {
        return DB::transaction(function () use ($messageId, $dispatchLeaseId): ?array {
            $context = $this->lockedMessageContext($messageId);
            if ($context === null) {
                return null;
            }

            $config = $context['config'];
            $message = $context['message'];

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
                $this->markUncertainRecord(
                    $message,
                    'Telegram delivery outcome is uncertain because the bot connection changed during sending.',
                );

                return null;
            }

            /** @var mixed $chunks */
            $chunks = $message->getAttribute('chunks');
            $nextChunk = $message->next_chunk + 1;

            if (! is_array($chunks)
                || $chunks === []
                || $nextChunk > count($chunks)
                || ! isset($chunks[$nextChunk - 1])
                || ! is_string($chunks[$nextChunk - 1])
            ) {
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
                'failed_at' => null,
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
            $context = $this->lockedMessageContext($messageId);
            if ($context === null) {
                return;
            }

            $message = $context['message'];
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
                'dispatch_claimed_at' => null,
                'dispatch_lease_id' => null,
            ])->save();
        });
    }

    private function markFailed(int $messageId, ?string $dispatchLeaseId, string $error): void
    {
        DB::transaction(function () use ($messageId, $dispatchLeaseId, $error): void {
            $context = $this->lockedMessageContext($messageId);
            if ($context === null) {
                return;
            }

            $message = $context['message'];
            if ($message === null
                || $message->status !== TelegramOutboundMessage::SENDING
                || ! $this->ownsDispatchClaim($message, $dispatchLeaseId)
            ) {
                return;
            }

            $message->forceFill([
                'status' => TelegramOutboundMessage::FAILED,
                'failed_at' => now(),
                'last_error' => $error,
                'dispatch_claimed_at' => null,
                'dispatch_lease_id' => null,
                'next_attempt_at' => null,
            ])->save();
        });
    }

    private function markUncertain(int $messageId, ?string $dispatchLeaseId, string $error): void
    {
        DB::transaction(function () use ($messageId, $dispatchLeaseId, $error): void {
            $context = $this->lockedMessageContext($messageId);
            if ($context === null) {
                return;
            }

            $message = $context['message'];
            if ($message === null
                || $message->status !== TelegramOutboundMessage::SENDING
                || ! $this->ownsDispatchClaim($message, $dispatchLeaseId)
            ) {
                return;
            }

            $this->markUncertainRecord(
                $message,
                'Telegram delivery outcome is uncertain. Verify the chat before retrying. '.$error,
            );
        });
    }

    private function markUncertainRecord(TelegramOutboundMessage $message, string $error): void
    {
        $message->forceFill([
            'status' => TelegramOutboundMessage::UNCERTAIN,
            'failed_at' => now(),
            'last_error' => $error,
            'dispatch_claimed_at' => null,
            'dispatch_lease_id' => null,
            'next_attempt_at' => null,
        ])->save();
    }

    private function discard(TelegramOutboundMessage $message, string $reason): void
    {
        $message->forceFill([
            'status' => TelegramOutboundMessage::DISCARDED,
            'discarded_at' => now(),
            'dispatch_claimed_at' => null,
            'dispatch_lease_id' => null,
            'next_attempt_at' => null,
            'last_error' => $reason,
        ])->save();
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

    private function releaseDispatchLease(int $messageId, ?string $dispatchLeaseId): void
    {
        if ($dispatchLeaseId === null) {
            return;
        }

        TelegramOutboundMessage::query()
            ->whereKey($messageId)
            ->where('status', TelegramOutboundMessage::PENDING)
            ->where('dispatch_lease_id', $dispatchLeaseId)
            ->update([
                'dispatch_claimed_at' => null,
                'dispatch_lease_id' => null,
                'updated_at' => now(),
            ]);
    }

    private function ownsDispatchClaim(TelegramOutboundMessage $message, ?string $dispatchLeaseId): bool
    {
        return $dispatchLeaseId === null
            ? $message->dispatch_lease_id === null
            : $message->dispatch_lease_id === $dispatchLeaseId;
    }
}
