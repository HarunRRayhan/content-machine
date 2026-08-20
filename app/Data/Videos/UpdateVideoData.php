<?php

namespace App\Data\Videos;

use App\Http\Requests\Videos\UpdateVideoRequest;

/**
 * Typed input for UpdateVideoAction. Covers only this slice's editable
 * surface (title/body); number/human_id/status/idea_id are set once at
 * promotion time and aren't editable here.
 */
final readonly class UpdateVideoData
{
    public function __construct(
        public string $title,
        public ?string $body,
    ) {}

    public static function fromRequest(UpdateVideoRequest $request): self
    {
        return new self(
            title: $request->string('title')->toString(),
            body: $request->filled('body') ? $request->string('body')->toString() : null,
        );
    }
}
