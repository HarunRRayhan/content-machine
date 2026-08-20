<?php

namespace App\Data\Posts;

use App\Http\Requests\Posts\UpdatePostRequest;

/**
 * Typed input for UpdatePostAction. Covers only this slice's editable
 * surface (title/body); number/human_id/status/idea_id are set once at
 * promotion time and aren't editable here.
 */
final readonly class UpdatePostData
{
    public function __construct(
        public string $title,
        public ?string $body,
    ) {}

    public static function fromRequest(UpdatePostRequest $request): self
    {
        return new self(
            title: $request->string('title')->toString(),
            body: $request->filled('body') ? $request->string('body')->toString() : null,
        );
    }
}
