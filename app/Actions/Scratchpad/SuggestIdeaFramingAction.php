<?php

namespace App\Actions\Scratchpad;

use App\Models\ScratchpadEntry;
use App\Support\AiProviders\AiCompletionClientContract;
use App\Support\AiProviders\AiProviderCredentialResolver;
use App\Support\AiProviders\IdeaSuggestion;

/**
 * Suggests a title/score/trend/rationale for filing a scratchpad entry as
 * a post or video idea, for the triage panel's "Suggest with AI" button.
 * Purely advisory: nothing here writes to the entry or creates an Idea,
 * TriageScratchpadEntryAction still owns that, and a user can freely edit
 * or ignore the suggestion before submitting.
 */
class SuggestIdeaFramingAction
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
        You help frame a raw capture as a scored content idea for someone's
        personal short-form video/post pipeline. Given the capture below,
        respond with ONLY a JSON object, no markdown fences and no other
        text, matching exactly this shape:
        {"title": string, "score": integer from 0 to 1000, "trend": "evergreen" or "seasonal", "rationale": string}
        "score" reflects how compelling and timely the idea is. "trend" is
        "evergreen" if it stays relevant indefinitely, "seasonal" if it's
        tied to a moment. "rationale" is 1-2 plain sentences on why it
        belongs in the pipeline, in the same language as the capture.
        PROMPT;

    public function __construct(
        private readonly AiCompletionClientContract $client,
        private readonly AiProviderCredentialResolver $resolver,
    ) {}

    public function handle(ScratchpadEntry $entry, string $kind): IdeaSuggestion
    {
        $userContent = $this->buildUserContent($entry, $kind);
        $chain = $this->resolver->textChain($entry->workspace);

        foreach ($chain as $modelEntry) {
            $result = $this->client->complete($modelEntry, self::SYSTEM_PROMPT, $userContent);

            if (! $result->successful) {
                continue;
            }

            $suggestion = $this->parse((string) $result->text);

            if ($suggestion !== null) {
                return $suggestion;
            }
        }

        return IdeaSuggestion::failure('No AI-generated suggestion is available right now.');
    }

    private function buildUserContent(ScratchpadEntry $entry, string $kind): string
    {
        $lines = ["Considering this as a {$kind} idea.", "Capture kind: {$entry->kind}"];

        if ($entry->title !== null) {
            $lines[] = "Title: {$entry->title}";
        }

        if ($entry->body !== null) {
            $lines[] = "Content: {$entry->body}";
        }

        if ($transcript = $entry->transcriptions->first()?->text) {
            $lines[] = "Transcript: {$transcript}";
        }

        if ($url = $entry->meta['url'] ?? null) {
            $lines[] = "URL: {$url}";
        }

        return implode(PHP_EOL, $lines);
    }

    private function parse(string $text): ?IdeaSuggestion
    {
        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            return null;
        }

        $title = $decoded['title'] ?? null;
        $score = $decoded['score'] ?? null;
        $trend = $decoded['trend'] ?? null;
        $rationale = $decoded['rationale'] ?? null;

        if (! is_string($title) || $title === '') {
            return null;
        }

        if (! is_int($score) && ! (is_string($score) && ctype_digit($score))) {
            return null;
        }

        $score = (int) $score;

        if ($score < 0 || $score > 1000) {
            return null;
        }

        if (! in_array($trend, ['evergreen', 'seasonal'], true)) {
            return null;
        }

        if (! is_string($rationale) || $rationale === '') {
            return null;
        }

        return IdeaSuggestion::success($title, $score, $trend, $rationale);
    }
}
