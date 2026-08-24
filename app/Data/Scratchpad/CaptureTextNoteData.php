<?php

namespace App\Data\Scratchpad;

use App\Http\Requests\Scratchpad\StoreScratchpadTextNoteRequest;

/**
 * Typed input for CaptureTextNoteAction. $source defaults to 'web' so
 * every existing construction (the dashboard's own StoreScratchpadTextNoteRequest
 * flow, and every test written before Telegram capture existed) keeps
 * working unchanged; fromTelegram() and fromApi() are the other callers.
 */
final readonly class CaptureTextNoteData
{
    public function __construct(
        public string $body,
        public string $source = 'web',
    ) {}

    public static function fromRequest(StoreScratchpadTextNoteRequest $request): self
    {
        return new self(
            body: $request->string('body')->toString(),
            source: 'web',
        );
    }

    public static function fromTelegram(string $body): self
    {
        return new self(body: $body, source: 'telegram');
    }

    public static function fromApi(string $body): self
    {
        return new self(body: $body, source: 'api');
    }
}
