<?php

namespace App\Data\AiProviders;

use App\Http\Requests\AiProviders\AddAiProviderCredentialModelsRequest;

final readonly class AddAiProviderCredentialModelsData
{
    /**
     * @param  list<string>  $models
     */
    public function __construct(
        public array $models,
        public string $purpose,
    ) {}

    public static function fromRequest(AddAiProviderCredentialModelsRequest $request): self
    {
        return new self(
            models: array_values($request->array('models')),
            purpose: $request->string('purpose')->toString(),
        );
    }
}
