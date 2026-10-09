<?php

namespace App\Console\Commands;

use App\Models\Idea;
use App\Support\Content\SourceFields;
use Illuminate\Console\Command;

class BackfillIdeaSourcesCommand extends Command
{
    protected $signature = 'ideas:backfill-sources {--dry-run : Report what would change without writing}';

    protected $description = 'Fill empty idea source_links/source_text from the scratchpad entry each idea was triaged from.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $updated = 0;

        Idea::query()
            ->withoutGlobalScopes()
            ->whereNotNull('scratchpad_entry_id')
            ->whereNull('source_links')
            ->whereNull('source_text')
            ->with(['scratchpadEntry' => fn ($query) => $query->withoutGlobalScopes()])
            ->chunkById(100, function ($ideas) use ($dryRun, &$updated): void {
                foreach ($ideas as $idea) {
                    $entry = $idea->scratchpadEntry;

                    if ($entry === null) {
                        continue;
                    }

                    $url = $entry->meta['url'] ?? null;
                    $links = is_string($url) && $url !== ''
                        ? SourceFields::normalizeLinks([['url' => $url, 'label' => $entry->title]])
                        : null;
                    $text = SourceFields::normalizeText($entry->body);

                    if ($links === null && $text === null) {
                        continue;
                    }

                    $updated++;

                    if (! $dryRun) {
                        $idea->forceFill(['source_links' => $links, 'source_text' => $text])->saveQuietly();
                    }
                }
            });

        $this->info(($dryRun ? 'Would update ' : 'Updated ').$updated.' idea(s).');

        return self::SUCCESS;
    }
}
