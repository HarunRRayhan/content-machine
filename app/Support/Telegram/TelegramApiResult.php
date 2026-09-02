<?php

namespace App\Support\Telegram;

/**
 * The generic pass/fail shape for setWebhook/deleteWebhook/sendMessage,
 * where getMe's extra $username field (TelegramGetMeResult) isn't needed.
 * $error is a message safe to show back to the user, null on success. Telegram
 * rate-limit responses carry retry_after so the outbox can remain pending
 * until Telegram allows the next attempt. A transport failure has an unknown
 * delivery outcome because Telegram may have accepted the request before the
 * connection broke.
 */
final readonly class TelegramApiResult
{
    private function __construct(
        public bool $successful,
        public ?string $error = null,
        public ?int $retryAfterSeconds = null,
        public ?int $status = null,
        public bool $outcomeUnknown = false,
    ) {}

    public static function success(): self
    {
        return new self(successful: true);
    }

    public static function failure(
        string $error,
        ?int $retryAfterSeconds = null,
        ?int $status = null,
        bool $outcomeUnknown = false,
    ): self {
        return new self(
            successful: false,
            error: $error,
            retryAfterSeconds: $retryAfterSeconds,
            status: $status,
            outcomeUnknown: $outcomeUnknown,
        );
    }
}
