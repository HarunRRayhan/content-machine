<?php

namespace App\Data\AiProviders;

use App\Http\Requests\AiProviders\StoreAiProviderCredentialRequest;

/**
 * Typed input for CreateAiProviderCredentialAction. No $model: which
 * model to use is resolved after saving, by checking the provider's own
 * list-models endpoint, not asked for upfront.
 */
final readonly class CreateAiProviderCredentialData
{
    public function __construct(
        public string $label,
        public string $provider,
        public ?string $baseUrl,
        public string $apiKey,
    ) {}

    public static function fromRequest(StoreAiProviderCredentialRequest $request): self
    {
        return new self(
            label: $request->string('label')->toString(),
            provider: $request->string('provider')->toString(),
            baseUrl: $request->filled('base_url') ? $request->string('base_url')->toString() : null,
            apiKey: $request->string('api_key')->toString(),
        );
    }
}
