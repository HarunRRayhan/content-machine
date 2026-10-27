<?php

namespace App\Actions\Notifications;

use App\Actions\Telegram\QueueTelegramMessageAction;
use App\Data\Notifications\CreateTelegramNotificationData;
use App\Models\TelegramBotConfig;
use App\Models\TelegramBotLink;
use App\Models\TelegramOutboundMessage;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Telegram\TelegramMessageChunker;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Queues a token owner's notification through the existing durable Telegram outbox.
 */
class QueueTelegramNotificationAction
{
    public function __construct(
        private readonly QueueTelegramMessageAction $queueMessage,
    ) {}

    /**
     * @return array{message: TelegramOutboundMessage, created: bool}
     */
    public function handle(
        Workspace $workspace,
        User $owner,
        CreateTelegramNotificationData $data,
    ): array {
        $logicalKey = "api-notification:user:{$owner->id}:{$data->key}";
        $chunks = TelegramMessageChunker::split($data->text);

        /** @var array{message: TelegramOutboundMessage, created: bool} $result */
        $result = DB::transaction(function () use ($workspace, $owner, $data, $logicalKey, $chunks): array {
            $lockedWorkspace = Workspace::query()
                ->whereKey($workspace->id)
                ->lockForUpdate()
                ->firstOrFail();

            $membership = DB::table('team_user')
                ->where('team_id', $lockedWorkspace->team_id)
                ->where('user_id', $owner->id)
                ->lockForUpdate()
                ->exists();

            if (! $membership) {
                throw new AuthorizationException('The token owner is no longer a workspace member.');
            }

            $config = TelegramBotConfig::query()
                ->where('workspace_id', $lockedWorkspace->id)
                ->lockForUpdate()
                ->first();

            if ($config === null || ! $config->isConnected() || $config->connection_operation !== null) {
                throw new TelegramNotificationsUnavailable;
            }

            $link = TelegramBotLink::query()
                ->where('telegram_bot_config_id', $config->id)
                ->where('user_id', $owner->id)
                ->lockForUpdate()
                ->first();

            if ($link === null) {
                throw new TelegramNotificationsUnavailable;
            }

            // The existing outbox key is unique per bot generation. Search all
            // generations first so a reconnect cannot replay a caller's key.
            $existing = TelegramOutboundMessage::query()
                ->where('telegram_bot_config_id', $config->id)
                ->where('logical_key', $logicalKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $this->assertSameRequest($existing, $link->telegram_user_id, $chunks);

                return ['message' => $existing, 'created' => false];
            }

            $message = $this->queueMessage->handle(
                config: $config,
                chatId: $link->telegram_user_id,
                text: $data->text,
                logicalKey: $logicalKey,
                webhookGeneration: $config->webhook_generation,
            );

            $this->assertSameRequest($message, $link->telegram_user_id, $chunks);

            return ['message' => $message, 'created' => true];
        });

        return $result;
    }

    /**
     * @param  list<string>  $chunks
     */
    private function assertSameRequest(TelegramOutboundMessage $message, int $chatId, array $chunks): void
    {
        if ($message->chat_id !== $chatId || $message->chunks !== $chunks) {
            throw new TelegramNotificationKeyConflict;
        }
    }
}
