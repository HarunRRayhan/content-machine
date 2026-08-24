<?php

namespace App\Data\ApiTokens;

use App\Http\Requests\ApiTokens\StoreWorkspaceApiTokenRequest;
use App\Models\WorkspaceApiToken;

/**
 * Typed input for CreateWorkspaceApiTokenAction. Abilities default to the
 * full set — a minted token is useful out of the box; the request narrows
 * them when the UI sends fewer.
 */
final readonly class CreateWorkspaceApiTokenData
{
    /**
     * @param  array<int, string>  $abilities
     */
    public function __construct(
        public string $name,
        public array $abilities = WorkspaceApiToken::ABILITIES,
    ) {}

    public static function fromRequest(StoreWorkspaceApiTokenRequest $request): self
    {
        return new self(
            name: $request->string('name')->toString(),
            abilities: collect($request->array('abilities'))
                ->map(fn ($ability) => (string) $ability)
                ->values()
                ->all(),
        );
    }
}
