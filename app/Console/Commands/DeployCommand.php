<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Runs every step Railway's preDeployCommand needs, in one Artisan
 * invocation. Deliberately NOT a shell-chained "migrate && cm:ensure-admin"
 * string in railway.web.json: on this project's Railway service,
 * preDeployCommand as a single `&&`-joined string only ran the first
 * command — no error, no non-zero exit, the second step just silently
 * never happened (confirmed: the admin user it should have created never
 * existed, twice, across separate deploys). Calling both via Artisan::call
 * inside one PHP process sidesteps whatever Railway's shell-chaining
 * behavior actually is, rather than depending on it.
 */
class DeployCommand extends Command
{
    protected $signature = 'cm:deploy';

    protected $description = 'Run pending migrations and ensure the admin user exists (preDeployCommand entry point)';

    public function handle(): int
    {
        $this->ensureScratchpadUploadsDirectory();
        $this->call('migrate', ['--force' => true]);
        $this->call('cm:ensure-admin');

        return self::SUCCESS;
    }

    /**
     * The scratchpad disk root is storage/app/uploads, which is also the
     * Railway volume mount. The directory is gitignored, so it is not in
     * the image, and a fresh volume is owned by root. Create it here so
     * the first photo/post-image write does not have to mkdir under a
     * root-owned mount as www-data.
     */
    private function ensureScratchpadUploadsDirectory(): void
    {
        $root = Storage::disk('scratchpad')->path('');

        if (! is_dir($root) && ! mkdir($root, 0775, true) && ! is_dir($root)) {
            $this->warn("Could not create scratchpad uploads directory at {$root}");

            return;
        }

        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            @chown($root, 'www-data');
            @chgrp($root, 'www-data');
        }
    }
}
