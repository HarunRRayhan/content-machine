<?php

namespace App\Support\AiProviders;

/**
 * What AiTranscriptionClientContract::transcribe() found out. $error is a
 * message safe to store in transcriptions.error_message and show back to
 * the user. $language is whatever the provider reports (often a language
 * name like "bengali", not an ISO code) — stored as-is, matching how
 * scratchpad_entries.language is already just a free-text string.
 */
final readonly class AiTranscriptionResult
{
    private function __construct(
        public bool $successful,
        public ?string $text = null,
        public ?string $language = null,
        public ?string $error = null,
    ) {}

    public static function success(string $text, ?string $language): self
    {
        return new self(successful: true, text: $text, language: $language);
    }

    public static function failure(string $error): self
    {
        return new self(successful: false, error: $error);
    }
}
