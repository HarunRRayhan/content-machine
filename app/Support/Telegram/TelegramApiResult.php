<?php

namespace App\Support\Telegram;

/**
 * The generic pass/fail shape for setWebhook/deleteWebhook/sendMessage,
 * where getMe's extra $username field (TelegramGetMeResult) isn't needed.
 * $error is a message safe to show back to the user, null on success.
 */
final readonly class TelegramApiResult
{
    private function __construct(
        public bool $successful,
        public ?string $error = null,
    ) {}

    public static function success(): self
    {
        return new self(successful: true);
    }

    public static function failure(string $error): self
    {
        return new self(successful: false, error: $error);
    }
}
