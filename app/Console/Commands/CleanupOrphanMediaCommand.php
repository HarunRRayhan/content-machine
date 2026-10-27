<?php

namespace App\Console\Commands;

use App\Actions\Media\CleanupOrphanMediaAction;
use Illuminate\Console\Command;
use RuntimeException;

class CleanupOrphanMediaCommand extends Command
{
    protected $signature = 'cm:cleanup-orphan-media {--delete : Delete confirmed orphan files; without this option the command only reports} {--grace-hours=168 : Minimum file age in hours (cannot be less than 168)}';

    protected $description = 'Report or explicitly delete old, unreferenced files from the managed local scratchpad media store';

    public function handle(CleanupOrphanMediaAction $cleanupOrphanMedia): int
    {
        $graceHours = filter_var($this->option('grace-hours'), FILTER_VALIDATE_INT);
        if ($graceHours === false || $graceHours < 168) {
            $this->error('The grace period must be an integer of at least 168 hours.');

            return self::FAILURE;
        }

        try {
            $result = $cleanupOrphanMedia->handle((bool) $this->option('delete'), $graceHours);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($result->files as $file) {
            $this->line("{$file['status']}: {$file['path']} ({$file['reason']})");
        }

        $eligible = count(array_filter($result->files, fn (array $file): bool => $file['status'] === 'orphan'));
        $quarantined = count(array_filter($result->files, fn (array $file): bool => $file['status'] === 'quarantined'));
        $this->info("{$eligible} eligible orphan(s); {$quarantined} quarantined; {$result->deleted} permanently deleted; {$result->skipped} skipped.");

        return self::SUCCESS;
    }
}
