<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\Workspace;
use App\Support\Media\PostDesignTemplate;
use Illuminate\Console\Command;

class BackfillPostTemplatesCommand extends Command
{
    protected $signature = 'posts:backfill-templates {workspace? : Workspace id (default: all)}';

    protected $description = 'Set posts.template from known human_id → letter map (Template A–F catalog).';

    /**
     * Known post → template letter from personal-content history.
     *
     * @var array<string, string>
     */
    private const KNOWN = [
        'P-29' => 'A',
        'P-34' => 'A',
        'P-50' => 'A',
        'P-53' => 'A',
        'P-58' => 'A',
        'P-59' => 'A',
        'P-30' => 'B',
        'P-43' => 'B',
        'P-44' => 'B',
        'P-54' => 'B',
        'P-56' => 'B',
        'P-60' => 'B',
        'P-66' => 'B',
        'P-31' => 'C',
        'P-35' => 'C',
        'P-41' => 'C',
        'P-48' => 'C',
        'P-51' => 'C',
        'P-52' => 'C',
        'P-57' => 'C',
        'P-61' => 'C',
        'P-62' => 'C',
        'P-67' => 'C',
        'P-63' => 'D',
        'P-64' => 'E',
        'P-65' => 'F',
    ];

    public function handle(): int
    {
        $workspaceId = $this->argument('workspace');
        $query = Post::query()->whereIn('human_id', array_keys(self::KNOWN));

        if ($workspaceId !== null) {
            $query->where('workspace_id', (int) $workspaceId);
        }

        $updated = 0;
        foreach ($query->cursor() as $post) {
            $letter = self::KNOWN[$post->human_id] ?? null;
            if ($letter === null || PostDesignTemplate::tryFrom($letter) === null) {
                continue;
            }
            if ($post->template === $letter) {
                continue;
            }
            $post->forceFill(['template' => $letter])->save();
            $updated++;
            $this->line("{$post->human_id} → Template {$letter}");
        }

        $this->info("Updated {$updated} post(s). Catalog letters: ".implode(', ', PostDesignTemplate::LETTERS));

        if ($workspaceId === null) {
            $this->comment('Workspaces: '.Workspace::query()->count());
        }

        return self::SUCCESS;
    }
}
