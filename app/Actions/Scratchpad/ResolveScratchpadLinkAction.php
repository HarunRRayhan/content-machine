<?php

namespace App\Actions\Scratchpad;

use App\Models\ScratchpadEntry;
use App\Models\TelegramBotConfig;
use App\Models\Workspace;
use App\Support\LinkResolution\LinkResolverContract;
use Illuminate\Support\Facades\DB;

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
        $resolvedBody = $resolved->description ?? $entry->body;

        DB::transaction(function () use ($entry, $resolved, $resolvedBody): void {
            $entryReference = ScratchpadEntry::query()
                ->whereKey($entry->id)
                ->first(['workspace_id', 'source', 'webhook_generation']);

            if ($entryReference === null) {
                return;
            }

            if ($entryReference->source === 'telegram') {
                Workspace::query()
                    ->whereKey($entryReference->workspace_id)
                    ->lockForUpdate()
                    ->first();

                $configReference = TelegramBotConfig::query()
                    ->where('workspace_id', $entryReference->workspace_id)
                    ->first(['id', 'webhook_generation']);

                if ($configReference === null) {
                    return;
                }

                $config = TelegramBotConfig::query()
                    ->whereKey($configReference->id)
                    ->lockForUpdate()
                    ->first();

                if ($config === null
                    || ! $config->isConnected()
                ) {
                    return;
                }

                $lockedEntry = ScratchpadEntry::query()
                    ->whereKey($entry->id)
                    ->lockForUpdate()
                    ->first();

                if ($lockedEntry === null) {
                    return;
                }

                if ($lockedEntry->webhook_generation === null
                    && $config->webhook_generation !== null
                ) {
                    $lockedEntry->webhook_generation = $config->webhook_generation;
                } elseif ($lockedEntry->webhook_generation !== $config->webhook_generation) {
                    return;
                }
            } else {
                $lockedEntry = ScratchpadEntry::query()
                    ->whereKey($entry->id)
                    ->lockForUpdate()
                    ->first();

                if ($lockedEntry === null) {
                    return;
                }
            }

            $lockedEntry->forceFill([
                'title' => $resolved->title,
                'body' => $resolvedBody,
                'meta' => [
                    ...$lockedEntry->meta,
                    'resolved_via' => $resolved->resolvedVia,
                    'resolved_kind' => $resolved->kind,
                    'resolved_description' => $resolvedBody,
                    'thumbnail_url' => $resolved->thumbnailUrl,
                    'resolved_at' => now()->toIso8601String(),
                ],
            ])->save();
        });
    }
}
