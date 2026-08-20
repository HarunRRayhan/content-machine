<?php

namespace App\Data\Scratchpad;

use App\Http\Requests\Scratchpad\StoreScratchpadLinkRequest;

/**
 * Typed input for CaptureScratchpadLinkAction.
 */
final readonly class CaptureScratchpadLinkData
{
    public function __construct(
        public string $url,
    ) {}

    public static function fromRequest(StoreScratchpadLinkRequest $request): self
    {
        return new self(
            url: $request->string('url')->toString(),
        );
    }
}
