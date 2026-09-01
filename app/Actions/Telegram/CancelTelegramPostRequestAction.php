<?php

namespace App\Actions\Telegram;

use App\Models\TelegramPostRequest;

/**
 * Cancels a pending Telegram post request without deleting its source capture
 * or an already-generated draft from Content Machine.
 */
class CancelTelegramPostRequestAction
{
    public function handle(TelegramPostRequest $request): TelegramPostRequest
    {
        TelegramPostRequest::query()
            ->whereKey($request->id)
            ->whereIn('state', TelegramPostRequest::ACTIVE_STATES)
            ->update([
                'state' => TelegramPostRequest::CANCELLED,
                'cancelled_at' => now(),
            ]);

        return $request->fresh() ?? $request;
    }
}
