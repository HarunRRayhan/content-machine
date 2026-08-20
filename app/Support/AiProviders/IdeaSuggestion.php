<?php

namespace App\Support\AiProviders;

/**
 * What SuggestIdeaFramingAction came up with for a triage panel: a
 * title/score/trend/rationale the user can accept as-is or edit before
 * filing. Never persisted anywhere, purely an ephemeral suggestion
 * rendered back into the triage form.
 */
final readonly class IdeaSuggestion
{
    private function __construct(
        public bool $successful,
        public ?string $title = null,
        public ?int $score = null,
        public ?string $trend = null,
        public ?string $rationale = null,
        public ?string $error = null,
    ) {}

    public static function success(string $title, int $score, string $trend, string $rationale): self
    {
        return new self(successful: true, title: $title, score: $score, trend: $trend, rationale: $rationale);
    }

    public static function failure(string $error): self
    {
        return new self(successful: false, error: $error);
    }
}
