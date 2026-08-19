<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

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
        $this->call('migrate', ['--force' => true]);
        $this->call('cm:ensure-admin');

        return self::SUCCESS;
    }
}
