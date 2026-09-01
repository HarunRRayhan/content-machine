<?php

namespace App\Actions\Telegram;

use App\Models\Post;
use App\Models\TelegramBotConfig;
use App\Models\TelegramBotLink;
use App\Models\TelegramPostRequest;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Cancels a pending Telegram post request without deleting its source capture
 * or an already-generated draft from Content Machine.
 */
class CancelTelegramPostRequestAction
{
    public function handle(
        TelegramPostRequest $request,
        TelegramBotConfig $config,
        int $telegramUserId,
        int $telegramChatId,
    ): TelegramPostRequest {
        return DB::transaction(function () use ($request, $config, $telegramUserId, $telegramChatId): TelegramPostRequest {
            // Publishing locks Post before TelegramPostRequest. Take the same
            // order here so cancellation cannot race a worker into a partial
            // publish or deadlock against its admission check.
            $postId = TelegramPostRequest::query()->whereKey($request->id)->value('post_id');
            $post = $postId === null
                ? null
                : Post::query()->whereKey($postId)->lockForUpdate()->first();

            $locked = TelegramPostRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->telegram_bot_config_id !== $config->id
                || $locked->workspace_id !== $config->workspace_id
                || $locked->telegram_user_id !== $telegramUserId
                || $locked->telegram_chat_id !== $telegramChatId
                || ! TelegramBotLink::query()
                    ->where('telegram_bot_config_id', $config->id)
                    ->where('telegram_user_id', $telegramUserId)
                    ->exists()
            ) {
                throw new RuntimeException('This Telegram post request does not belong to your account.');
            }

            if (! in_array($locked->state, TelegramPostRequest::ACTIVE_STATES, true)) {
                return $locked;
            }

            if ($post !== null && in_array($post->publish_state, ['queued', 'running'], true)) {
                return $locked;
            }

            $locked->forceFill([
                'state' => TelegramPostRequest::CANCELLED,
                'cancelled_at' => now(),
            ])->save();

            return $locked;
        });
    }
}
