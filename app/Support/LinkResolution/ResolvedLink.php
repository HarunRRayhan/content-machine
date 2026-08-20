<?php

namespace App\Support\LinkResolution;

/**
 * What LinkResolverContract::resolve() honestly managed to find out about a
 * forwarded URL. $resolvedVia is never a summary of confidence, it names
 * the exact rung that produced the rest of the data, so a scratchpad entry
 * can say precisely what was and wasn't read (e.g. "page metadata only,
 * a carousel's own slide images were never seen").
 */
final readonly class ResolvedLink
{
    public function __construct(
        public string $kind,
        public string $resolvedVia,
        public ?string $title = null,
        public ?string $description = null,
        public ?string $thumbnailUrl = null,
    ) {}

    public static function unresolved(string $reason): self
    {
        return new self(kind: 'unresolved', resolvedVia: $reason);
    }
}
