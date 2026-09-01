<?php

namespace App\Actions\Posts;

use App\Models\Post;
use App\Models\TelegramPostRequest;
use App\Models\User;
use RuntimeException;

/**
 * The human approval gate for generated drafts. PostSyncer's confirm_ask
 * option is a platform capability check, not this content approval.
 */
class ApprovePostAction
{
    public function handle(Post $post, User $actor): Post
    {
        if ($post->approval_state === 'approved') {
            $post->telegramPostRequests()
                ->where('state', TelegramPostRequest::AWAITING_APPROVAL)
                ->update([
                    'state' => TelegramPostRequest::APPROVED,
                    'confirmed_at' => now(),
                    'error_message' => null,
                ]);

            return $post;
        }

        if (! in_array($post->status, ['draft', 'ready'], true)) {
            throw new RuntimeException('Only draft posts can be approved.');
        }

        $post->forceFill([
            'approval_state' => 'approved',
            'approved_at' => now(),
            'approved_by_user_id' => $actor->id,
        ])->save();

        $post->telegramPostRequests()
            ->where('state', TelegramPostRequest::AWAITING_APPROVAL)
            ->update([
                'state' => TelegramPostRequest::APPROVED,
                'confirmed_at' => now(),
                'error_message' => null,
            ]);

        return $post->fresh() ?? $post;
    }
}
