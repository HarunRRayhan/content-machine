<?php

namespace App\Data\Videos;

use App\Http\Requests\Videos\UpdatePresentationCueRequest;

final readonly class UpdatePresentationCueData
{
    public function __construct(
        public int $step,
        public string $currentCue,
        public string $cue,
    ) {}

    public static function fromRequest(UpdatePresentationCueRequest $request): self
    {
        return new self(
            step: $request->integer('step'),
            currentCue: $request->string('current_cue')->toString(),
            cue: $request->string('cue')->toString(),
        );
    }
}
