<?php

namespace App\Data\Ideas;

use App\Http\Requests\Ideas\DropIdeaRequest;

/**
 * Typed input for DropIdeaAction.
 */
final readonly class DropIdeaData
{
    public function __construct(
        public string $dropReason,
    ) {}

    public static function fromRequest(DropIdeaRequest $request): self
    {
        return new self(
            dropReason: $request->string('drop_reason')->toString(),
        );
    }
}
