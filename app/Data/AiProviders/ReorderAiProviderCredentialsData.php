<?php

namespace App\Data\AiProviders;

use App\Http\Requests\AiProviders\ReorderAiProviderCredentialsRequest;

/**
 * Typed input for ReorderAiProviderCredentialsAction. $orderedIds is the
 * full new priority order, index 0 becomes the new default.
 */
final readonly class ReorderAiProviderCredentialsData
{
    /**
     * @param  list<int>  $orderedIds
     */
    public function __construct(
        public array $orderedIds,
    ) {}

    public static function fromRequest(ReorderAiProviderCredentialsRequest $request): self
    {
        return new self(
            orderedIds: array_values(array_map(intval(...), $request->array('ordered_ids'))),
        );
    }
}
