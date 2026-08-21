<?php

namespace App\Data\AiProviders;

use App\Http\Requests\AiProviders\UpdateAiProviderCredentialRequest;

/**
 * Typed input for UpdateAiProviderCredentialAction. $apiKey is null when
 * the edit didn't touch the key (see UpdateAiProviderCredentialRequest's
 * docblock); the Action leaves the stored key untouched in that case
 * rather than overwriting it with an empty string.
 */
final readonly class UpdateAiProviderCredentialData
{
    public function __construct(
        public string $label,
        public ?string $baseUrl,
        public ?string $model,
        public ?string $apiKey,
    ) {}

    public static function fromRequest(UpdateAiProviderCredentialRequest $request): self
    {
        return new self(
            label: $request->string('label')->toString(),
            baseUrl: $request->filled('base_url') ? $request->string('base_url')->toString() : null,
            model: $request->filled('model') ? $request->string('model')->toString() : null,
            apiKey: $request->filled('api_key') ? $request->string('api_key')->toString() : null,
        );
    }
}
