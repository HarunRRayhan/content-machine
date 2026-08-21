<?php

namespace App\Data\AiProviders;

use App\Http\Requests\AiProviders\ReorderAiProviderCredentialModelsRequest;

final readonly class ReorderAiProviderCredentialModelsData
{
    /**
     * @param  list<int>  $orderedIds
     */
    public function __construct(
        public string $purpose,
        public array $orderedIds,
    ) {}

    public static function fromRequest(ReorderAiProviderCredentialModelsRequest $request): self
    {
        return new self(
            purpose: $request->string('purpose')->toString(),
            orderedIds: array_values(array_map(intval(...), $request->array('ordered_ids'))),
        );
    }
}
