<?php

namespace App\Support\Telegram;

/**
 * What TelegramClientContract::downloadFile() found out about a file_id.
 * $error is a message safe to show back to the user — including, honestly,
 * Telegram's own "file is too big" description when a file exceeds the
 * Bot API's 20MB download cap, since that's a real, specific reason
 * capture didn't happen.
 */
final readonly class TelegramFileDownloadResult
{
    private function __construct(
        public bool $successful,
        public ?string $contents = null,
        public ?string $error = null,
    ) {}

    public static function success(string $contents): self
    {
        return new self(successful: true, contents: $contents);
    }

    public static function failure(string $error): self
    {
        return new self(successful: false, error: $error);
    }
}
