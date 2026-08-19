<?php

namespace App\Data\Scratchpad;

use App\Http\Requests\Scratchpad\StoreScratchpadTextNoteRequest;

/**
 * Typed input for CaptureTextNoteAction.
 */
final readonly class CaptureTextNoteData
{
    public function __construct(
        public string $body,
    ) {}

    public static function fromRequest(StoreScratchpadTextNoteRequest $request): self
    {
        return new self(
            body: $request->string('body')->toString(),
        );
    }
}
