<?php

namespace App\Data\Scratchpad;

use App\Http\Requests\Api\UpdateScratchpadEntryRequest;

/**
 * Typed input for UpdateScratchpadEntryAction. Null means "not sent, leave
 * alone" — a PATCH only changes the fields it names. changes() turns that
 * into the field => new-value map the Action diffs against the entry.
 */
final readonly class UpdateScratchpadEntryData
{
    public function __construct(
        public ?string $title,
        public ?string $body,
        public ?string $language,
    ) {}

    public static function fromRequest(UpdateScratchpadEntryRequest $request): self
    {
        return new self(
            title: $request->filled('title') ? $request->string('title')->toString() : null,
            body: $request->filled('body') ? $request->string('body')->toString() : null,
            language: $request->filled('language') ? $request->string('language')->toString() : null,
        );
    }

    /**
     * @return array<string, string>
     */
    public function changes(): array
    {
        return array_filter(
            ['title' => $this->title, 'body' => $this->body, 'language' => $this->language],
            fn ($value) => $value !== null,
        );
    }
}
