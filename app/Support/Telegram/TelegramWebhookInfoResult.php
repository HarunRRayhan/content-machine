<?php

namespace App\Support\Telegram;

/**
 * The small subset of getWebhookInfo needed by the cutover preflight.
 */
final readonly class TelegramWebhookInfoResult
{
    private function __construct(
        public bool $successful,
        public ?string $url = null,
        public ?int $pendingUpdateCount = null,
        public ?string $error = null,
    ) {}

    public static function success(string $url, ?int $pendingUpdateCount = null): self
    {
        return new self(
            successful: true,
            url: $url,
            pendingUpdateCount: $pendingUpdateCount,
        );
    }

    public static function failure(string $error): self
    {
        return new self(successful: false, error: $error);
    }
}
