<?php

namespace App\Support\AiProviders;

/**
 * What AiCompletionClientContract::complete() found out. $error is a
 * message safe to log; nothing here is shown to the user directly, a
 * failed completion just leaves whatever content already existed in place
 * (see SummarizeCaptureAction).
 */
final readonly class AiCompletionResult
{
    private function __construct(
        public bool $successful,
        public ?string $text = null,
        public ?string $error = null,
    ) {}

    public static function success(string $text): self
    {
        return new self(successful: true, text: $text);
    }

    public static function failure(string $error): self
    {
        return new self(successful: false, error: $error);
    }
}
