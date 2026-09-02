<?php

namespace App\Actions\Telegram;

use App\Jobs\SendTelegramOutboundMessageJob;
use App\Models\TelegramBotConfig;
use App\Models\TelegramOutboundMessage;
use App\Support\Telegram\TelegramMessageChunker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * Persists a logical reply before delivery. Delivery is dispatched only after
 * the surrounding transaction commits, while the scheduled outbox drain is a
 * durable fallback when queue insertion is unavailable.
 */
class QueueTelegramMessageAction
{
    public function handle(
        TelegramBotConfig $config,
        int $chatId,
        string $text,
        string $logicalKey,
        ?string $webhookGeneration = null,
    ): TelegramOutboundMessage {
        if ($chatId === 0) {
            throw new InvalidArgumentException('A Telegram chat id is required.');
        }

        if (trim($text) === '') {
            throw new InvalidArgumentException('A Telegram message cannot be empty.');
        }

        if ($logicalKey === '' || mb_strlen($logicalKey) > 191) {
            throw new InvalidArgumentException('A Telegram message logical key is required.');
        }

        $chunks = TelegramMessageChunker::split($text);
        $encodedChunks = $this->encodeChunks($chunks);
        $generation = $webhookGeneration ?? $config->webhook_generation;

        /** @var array{message: TelegramOutboundMessage, created: bool} $result */
        $result = DB::transaction(function () use ($config, $chatId, $logicalKey, $generation, $encodedChunks, $chunks): array {
            $messageQuery = TelegramOutboundMessage::query()
                ->where('telegram_bot_config_id', $config->id)
                ->where('logical_key', $logicalKey);

            if ($generation === null) {
                $messageQuery->whereNull('webhook_generation');
            } else {
                $messageQuery->where('webhook_generation', $generation);
            }

            $message = $messageQuery->lockForUpdate()->first();

            if ($message !== null) {
                $storedGeneration = $message->webhook_generation;
                if ($message->chat_id !== $chatId
                    || $storedGeneration !== $generation
                    || $message->chunks !== $chunks
                ) {
                    Log::warning('Telegram outbound logical key was reused with different payload.', [
                        'message_id' => $message->id,
                        'telegram_bot_config_id' => $config->id,
                        'logical_key' => $logicalKey,
                    ]);
                }

                return ['message' => $message, 'created' => false];
            }

            $inserted = DB::table('telegram_outbound_messages')->insertOrIgnore([
                'telegram_bot_config_id' => $config->id,
                'webhook_generation' => $generation,
                'chat_id' => $chatId,
                'logical_key' => $logicalKey,
                'chunks' => $encodedChunks,
                'status' => TelegramOutboundMessage::PENDING,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $messageQuery = TelegramOutboundMessage::query()
                ->where('telegram_bot_config_id', $config->id)
                ->where('logical_key', $logicalKey);

            if ($generation === null) {
                $messageQuery->whereNull('webhook_generation');
            } else {
                $messageQuery->where('webhook_generation', $generation);
            }

            $message = $messageQuery->lockForUpdate()->firstOrFail();

            return ['message' => $message, 'created' => $inserted > 0];
        });

        $message = $result['message'];

        if ($result['created'] && $message->status === TelegramOutboundMessage::PENDING) {
            try {
                SendTelegramOutboundMessageJob::dispatch($message->id)->afterCommit();
            } catch (Throwable $exception) {
                // The row is committed before this dispatch is attempted; the
                // scheduled drain is the durable fallback.
                report($exception);
            }
        }

        return $message->fresh() ?? $message;
    }

    /**
     * @param  list<string>  $chunks
     */
    private function encodeChunks(array $chunks): string
    {
        try {
            return json_encode($chunks, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The Telegram message could not be encoded.', previous: $exception);
        }
    }
}
