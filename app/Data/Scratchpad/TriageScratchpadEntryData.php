<?php

namespace App\Data\Scratchpad;

use App\Http\Requests\Scratchpad\TriageScratchpadEntryRequest;

/**
 * Typed input for TriageScratchpadEntryAction. `$target` decides which
 * branch the Action takes ('post_idea'/'video_idea' file it as an Idea,
 * 'drop' just drops the entry); the rest of the fields are only relevant
 * to one branch or the other, see TriageScratchpadEntryRequest for which
 * ones are required for which target.
 */
final readonly class TriageScratchpadEntryData
{
    public function __construct(
        public string $target,
        public ?string $title = null,
        public ?int $score = null,
        public ?string $trend = null,
        public ?string $rationale = null,
        public ?string $dropReason = null,
    ) {}

    public static function fromRequest(TriageScratchpadEntryRequest $request): self
    {
        return new self(
            target: $request->string('target')->toString(),
            title: $request->filled('title') ? $request->string('title')->toString() : null,
            score: $request->filled('score') ? (int) $request->input('score') : null,
            trend: $request->filled('trend') ? $request->string('trend')->toString() : null,
            rationale: $request->filled('rationale') ? $request->string('rationale')->toString() : null,
            dropReason: $request->filled('drop_reason') ? $request->string('drop_reason')->toString() : null,
        );
    }
}
