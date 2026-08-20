<?php

namespace App\Actions\Scratchpad;

use App\Models\ScratchpadEntry;
use App\Support\LinkResolution\LinkResolverContract;

/**
 * Runs the deterministic resolution ladder against a link entry's URL and
 * records exactly what it found, never more. $entry->meta['url'] (set at
 * capture time) stays the source of truth for the original link even if a
 * later phase overwrites body with an AI-written summary.
 */
class ResolveScratchpadLinkAction
{
    public function __construct(
        private readonly LinkResolverContract $resolver,
    ) {}

    public function handle(ScratchpadEntry $entry): void
    {
        $url = $entry->meta['url'] ?? $entry->body;

        $resolved = $this->resolver->resolve((string) $url);

        $entry->update([
            'title' => $resolved->title,
            'body' => $resolved->description ?? $entry->body,
            'meta' => [
                ...$entry->meta,
                'resolved_via' => $resolved->resolvedVia,
                'resolved_kind' => $resolved->kind,
                'thumbnail_url' => $resolved->thumbnailUrl,
                'resolved_at' => now()->toIso8601String(),
            ],
        ]);
    }
}
