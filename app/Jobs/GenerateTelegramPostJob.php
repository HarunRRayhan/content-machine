<?php

namespace App\Jobs;

use App\Actions\Telegram\GenerateTelegramPostAction;
use App\Models\TelegramPostRequest;
use App\Support\Telegram\TelegramClientContract;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenerateTelegramPostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const OVERLAP_EXPIRES_AFTER_SECONDS = 960;

    public function __construct(
        public readonly int $telegramPostRequestId,
    ) {
        // Generated photo drafts read the scratchpad uploads volume. Keeping
        // every generation here also avoids a text/photo queue race.
        $this->onQueue('scratchpad');
    }

    public function handle(GenerateTelegramPostAction $action): void
    {
        $action->handle($this->telegramPostRequestId);
    }

    public function uniqueId(): string
    {
        return 'telegram-post-generation:'.$this->telegramPostRequestId;
    }

    /**
     * The request row is the durable completion guard. This lock only keeps
     * duplicate recovery dispatches from calling the AI provider together.
     *
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(
                'telegram-post-generation:'.$this->telegramPostRequestId,
                60,
                self::OVERLAP_EXPIRES_AFTER_SECONDS,
            ))->shared()->dontRelease(),
        ];
    }

    /**
     * Do not leave a request stuck in `generating` when an unexpected error
     * survives the queue worker's retries. Expected provider/storage failures
     * are handled by GenerateTelegramPostAction itself.
     */
    public function failed(Throwable $exception): void
    {
        report($exception);

        $request = TelegramPostRequest::query()
            ->whereKey($this->telegramPostRequestId)
            ->where('state', TelegramPostRequest::GENERATING)
            ->with('telegramBotConfig')
            ->first();

        if ($request === null) {
            return;
        }

        $message = 'I could not create the post draft because an unexpected error occurred.';
        $updated = TelegramPostRequest::query()
            ->whereKey($request->id)
            ->where('state', TelegramPostRequest::GENERATING)
            ->update([
                'state' => TelegramPostRequest::FAILED,
                'error_message' => $message,
            ]);

        if ($updated === 0) {
            return;
        }

        $config = $request->telegramBotConfig;
        if ($config !== null && $config->bot_token !== null) {
            app(TelegramClientContract::class)->sendMessage(
                $config->bot_token,
                $request->telegram_chat_id,
                "❌ {$message}",
            );
        }
    }
}
