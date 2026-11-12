<?php

namespace App\Support\Telegram;

use App\Models\Post;
use App\Models\TelegramBotConfig;
use App\Models\TelegramPostRequest;
use App\Support\Postsyncer\PostsyncerException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The Telegram request a publish was started for (`telegram_request_id` in the publish
 * options), shared by the PostSyncer and SocialReins publish paths so both admit, lock and
 * close it the same way. Every method expects the caller to hold the post's row lock:
 * the lock order is Post, TelegramBotConfig, TelegramPostRequest.
 */
class TelegramPublishRequests
{
    /**
     * @param  array<string, mixed>  $options
     *
     * @throws InvalidArgumentException when the id is present but not a positive integer
     */
    public static function idFrom(array $options): ?int
    {
        $id = $options['telegram_request_id'] ?? null;

        if ($id === null) {
            return null;
        }

        if (is_int($id) && $id > 0) {
            return $id;
        }

        if (is_string($id) && ctype_digit($id) && (int) $id > 0) {
            return (int) $id;
        }

        throw new InvalidArgumentException('The Telegram publish request id is invalid.');
    }

    /**
     * Enqueue time: serialize with disconnect/rotation by locking the bot config, then lock
     * the request and require it to be approved (or failed, on a retry). A retry without an
     * id picks up the post's one failed request, if any.
     */
    public static function admit(Post $post, ?int $requestId, bool $isRetry): ?TelegramPostRequest
    {
        if ($requestId === null) {
            if (! $isRetry) {
                return null;
            }

            $requests = $post->telegramPostRequests()
                ->where('state', TelegramPostRequest::FAILED)
                ->latest('id')
                ->limit(2)
                ->get(['id']);

            if ($requests->count() > 1) {
                throw self::refuse(__('Specify the Telegram post request to retry.'));
            }

            $requestId = $requests->first()?->id;

            if ($requestId === null) {
                return null;
            }
        }

        $request = TelegramPostRequest::query()->whereKey($requestId)->first();

        if ($request === null) {
            throw self::refuse(__('The Telegram publish request is invalid.'));
        }

        $config = TelegramBotConfig::query()
            ->whereKey($request->telegram_bot_config_id)
            ->lockForUpdate()
            ->first();

        if ($config === null
            || ! $config->isConnected()
            || $request->workspace_id !== $post->workspace_id
            || $request->post_id !== $post->id
            || ($request->webhook_generation !== null
                && $request->webhook_generation !== $config->webhook_generation)
        ) {
            throw self::refuse(__('This Telegram draft is no longer approved for publishing.'));
        }

        if ($request->webhook_generation === null && $config->webhook_generation !== null) {
            $request->forceFill([
                'webhook_generation' => $config->webhook_generation,
            ])->save();
        }

        $request = TelegramPostRequest::query()
            ->whereKey($requestId)
            ->lockForUpdate()
            ->first();

        if ($request === null
            || $request->workspace_id !== $post->workspace_id
            || $request->post_id !== $post->id
            || ! in_array($request->state, [
                TelegramPostRequest::APPROVED,
                ...($isRetry ? [TelegramPostRequest::FAILED] : []),
            ], true)
        ) {
            throw self::refuse(__('This Telegram draft is no longer approved for publishing.'));
        }

        return $request;
    }

    /**
     * Worker time: lock the bot config and the request. The caller decides what a cancelled
     * or published request means; any other state than approved or failed is refused.
     *
     * @throws PostsyncerException
     */
    public static function lock(Post $post, ?int $requestId): ?TelegramPostRequest
    {
        if ($requestId === null) {
            return null;
        }

        $request = TelegramPostRequest::query()->whereKey($requestId)->first();

        if ($request === null
            || $request->workspace_id !== $post->workspace_id
            || $request->post_id !== $post->id) {
            throw new PostsyncerException('The Telegram publish request does not belong to this post.');
        }

        $config = TelegramBotConfig::query()
            ->whereKey($request->telegram_bot_config_id)
            ->lockForUpdate()
            ->first();

        if ($config === null
            || ! $config->isConnected()
            || ($request->webhook_generation !== null
                && $request->webhook_generation !== $config->webhook_generation)
        ) {
            throw new PostsyncerException(
                'The Telegram bot connection changed before this publish could run.',
            );
        }

        $request = TelegramPostRequest::query()
            ->whereKey($requestId)
            ->lockForUpdate()
            ->first();

        if ($request === null
            || $request->workspace_id !== $post->workspace_id
            || $request->post_id !== $post->id
            || ($request->webhook_generation !== null
                && $request->webhook_generation !== $config->webhook_generation)) {
            throw new PostsyncerException('The Telegram publish request does not belong to this post.');
        }

        if ($request->webhook_generation === null && $config->webhook_generation !== null) {
            $request->forceFill([
                'webhook_generation' => $config->webhook_generation,
            ])->save();
        }

        if (! in_array($request->state, [
            TelegramPostRequest::APPROVED,
            TelegramPostRequest::FAILED,
            TelegramPostRequest::CANCELLED,
            TelegramPostRequest::PUBLISHED,
        ], true)) {
            throw new PostsyncerException('The Telegram draft is not ready to publish.');
        }

        return $request;
    }

    /**
     * Close (published, failed) the request a publish owns. A cancelled or already published
     * request is never touched. Dashboard/API publishes simply have no request.
     */
    public static function update(Post $post, string $state, ?string $errorMessage, ?int $requestId): void
    {
        if ($requestId === null) {
            return;
        }

        $post->telegramPostRequests()
            ->whereIn('state', [
                TelegramPostRequest::AWAITING_APPROVAL,
                TelegramPostRequest::APPROVED,
                TelegramPostRequest::FAILED,
            ])
            ->whereKey($requestId)
            ->update([
                'state' => $state,
                'error_message' => $errorMessage,
            ]);
    }

    private static function refuse(string $message): ValidationException
    {
        return ValidationException::withMessages(['publish' => $message]);
    }
}
