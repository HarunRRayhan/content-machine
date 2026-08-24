<?php

namespace App\Data\Scratchpad;

use App\Http\Requests\Scratchpad\StoreScratchpadLinkRequest;

/**
 * Typed input for CaptureScratchpadLinkAction. $source defaults to 'web',
 * see CaptureTextNoteData's docblock for why.
 */
final readonly class CaptureScratchpadLinkData
{
    public function __construct(
        public string $url,
        public string $source = 'web',
    ) {}

    public static function fromRequest(StoreScratchpadLinkRequest $request): self
    {
        return new self(
            url: $request->string('url')->toString(),
            source: 'web',
        );
    }

    public static function fromTelegram(string $url): self
    {
        return new self(url: $url, source: 'telegram');
    }

    public static function fromApi(string $url): self
    {
        return new self(url: $url, source: 'api');
    }
}
