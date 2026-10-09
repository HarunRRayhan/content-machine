<?php

namespace App\Data\Ideas;

use App\Http\Requests\Ideas\UpdateIdeaRequest;
use App\Support\Content\SourceFields;

/**
 * Typed input for UpdateIdeaAction. Covers only this slice's editable
 * surface (title/score/trend/rationale/body); kind/number/human_id/slug/
 * status are set once at triage time and aren't editable here.
 */
final readonly class UpdateIdeaData
{
    /**
     * @param  list<array{url: string, label: string|null}>|null  $sourceLinks
     */
    public function __construct(
        public string $title,
        public ?int $score,
        public ?string $trend,
        public ?string $rationale,
        public ?string $body,
        public ?array $sourceLinks = null,
        public ?string $sourceText = null,
        public bool $hasSourceLinks = false,
        public bool $hasSourceText = false,
    ) {}

    public static function fromRequest(UpdateIdeaRequest $request): self
    {
        return new self(
            title: $request->string('title')->toString(),
            score: $request->filled('score') ? (int) $request->input('score') : null,
            trend: $request->filled('trend') ? $request->string('trend')->toString() : null,
            rationale: $request->filled('rationale') ? $request->string('rationale')->toString() : null,
            body: $request->filled('body') ? $request->string('body')->toString() : null,
            sourceLinks: SourceFields::normalizeLinks($request->input('source_links')),
            sourceText: SourceFields::normalizeText($request->input('source_text')),
            hasSourceLinks: $request->has('source_links'),
            hasSourceText: $request->has('source_text'),
        );
    }
}
