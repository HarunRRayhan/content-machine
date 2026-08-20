<?php

namespace App\Support\AiProviders;

/**
 * What AiProviderVerifierContract::verify() found out about a credential.
 * $error is a message safe to show back to the user (never the raw
 * exception or response body), null on success.
 */
final readonly class AiProviderVerificationResult
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
