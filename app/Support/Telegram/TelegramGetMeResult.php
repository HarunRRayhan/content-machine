<?php

namespace App\Support\Telegram;

/**
 * What TelegramClientContract::getMe() found out about a bot token.
 * $error is a message safe to show back to the user, null on success.
 */
final readonly class TelegramGetMeResult
{
    private function __construct(
        public bool $successful,
        public ?string $username = null,
        public ?string $error = null,
    ) {}

    public static function success(string $username): self
    {
        return new self(successful: true, username: $username);
    }

    public static function failure(string $error): self
    {
        return new self(successful: false, error: $error);
    }
}
