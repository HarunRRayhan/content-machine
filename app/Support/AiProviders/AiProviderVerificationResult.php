<?php

namespace App\Support\AiProviders;

/**
 * What AiProviderVerifierContract::verify() found out about a credential.
 * $error is a message safe to show back to the user (never the raw
 * exception or response body), null on success. $models is the
 * normalized model list read off the same list-models call verification
 * already makes (empty array if the provider responded but listed none,
 * null on failure since nothing was read at all); CreateAiProviderCredentialAction
 * stores it as-is for the dashboard to offer as a picker.
 */
final readonly class AiProviderVerificationResult
{
    private function __construct(
        public bool $successful,
        public ?string $error = null,
        /** @var array<int, array{id: string, label: string}>|null */
        public ?array $models = null,
    ) {}

    /**
     * @param  array<int, array{id: string, label: string}>  $models
     */
    public static function success(array $models = []): self
    {
        return new self(successful: true, models: $models);
    }

    public static function failure(string $error): self
    {
        return new self(successful: false, error: $error);
    }
}
