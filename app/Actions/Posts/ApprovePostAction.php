<?php

namespace App\Actions\Posts;

use App\Models\Post;
use App\Models\TelegramBotConfig;
use App\Models\TelegramBotLink;
use App\Models\TelegramPostRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The human approval gate for generated drafts. PostSyncer's confirm_ask
 * option is a platform capability check, not this content approval.
 */
class ApprovePostAction
{
    public function handle(
        Post $post,
        User $actor,
        ?TelegramPostRequest $telegramRequest = null,
        ?TelegramBotConfig $telegramConfig = null,
        ?int $telegramUserId = null,
        ?int $telegramChatId = null,
    ): Post {
        return DB::transaction(function () use (
            $post,
            $actor,
            $telegramRequest,
            $telegramConfig,
            $telegramUserId,
            $telegramChatId,
        ): Post {
            $lockedPost = Post::query()
                ->whereKey($post->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedRequest = null;
            if ($telegramRequest !== null) {
                $lockedRequest = TelegramPostRequest::query()
                    ->whereKey($telegramRequest->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($lockedRequest === null
                    || $lockedRequest->post_id !== $lockedPost->id
                    || $lockedRequest->state !== TelegramPostRequest::AWAITING_APPROVAL
                ) {
                    throw ValidationException::withMessages([
                        'post' => __('This Telegram post request is no longer waiting for approval.'),
                    ]);
                }

                if ($telegramConfig === null
                    || $telegramUserId === null
                    || $telegramChatId === null
                    || $lockedRequest->telegram_bot_config_id !== $telegramConfig->id
                    || $lockedRequest->workspace_id !== $telegramConfig->workspace_id
                    || $lockedRequest->telegram_user_id !== $telegramUserId
                    || $lockedRequest->telegram_chat_id !== $telegramChatId
                    || ($lockedRequest->webhook_generation !== null
                        && $lockedRequest->webhook_generation !== $telegramConfig->webhook_generation)
                    || ! TelegramBotLink::query()
                        ->where('telegram_bot_config_id', $telegramConfig->id)
                        ->where('telegram_user_id', $telegramUserId)
                        ->where('user_id', $actor->id)
                        ->exists()
                ) {
                    throw ValidationException::withMessages([
                        'post' => __('This Telegram post request does not belong to your account.'),
                    ]);
                }

                if ($lockedRequest->webhook_generation === null && $telegramConfig->webhook_generation !== null) {
                    $lockedRequest->forceFill([
                        'webhook_generation' => $telegramConfig->webhook_generation,
                    ])->save();
                }
            }

            if ($lockedPost->approval_state !== 'approved') {
                // Re-approval must also work after a publish went out, so a
                // retry can be authorized (P-73: approval is one-shot and the
                // post sits at scheduled, which used to 500 here).
                if (! in_array($lockedPost->status, ['draft', 'ready', 'scheduled', 'posted'], true)) {
                    throw ValidationException::withMessages([
                        'post' => __('Only draft, ready, scheduled, or posted posts can be approved.'),
                    ]);
                }

                $lockedPost->forceFill([
                    'approval_state' => 'approved',
                    'approved_at' => now(),
                    'approved_by_user_id' => $actor->id,
                ])->save();
            }

            $requests = $lockedPost->telegramPostRequests()
                ->where('state', TelegramPostRequest::AWAITING_APPROVAL);
            if ($lockedRequest !== null) {
                $requests->whereKey($lockedRequest->id);
            }
            $requests->update([
                'state' => TelegramPostRequest::APPROVED,
                'confirmed_at' => now(),
                'error_message' => null,
            ]);

            return $lockedPost->fresh() ?? $lockedPost;
        });
    }
}
