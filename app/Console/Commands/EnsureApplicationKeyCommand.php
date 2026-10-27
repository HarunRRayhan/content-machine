<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class EnsureApplicationKeyCommand extends Command
{
    protected $signature = 'cm:ensure-key {--require-existing : Fail instead of generating a missing key}';

    protected $description = 'Preserve the application key, generating it only for a new local installation';

    public function handle(): int
    {
        if (filled(config('app.key'))) {
            $this->components->info('Application key already configured, keeping it.');

            return self::SUCCESS;
        }

        if ($this->option('require-existing') || app()->environment('production', 'prod')) {
            $this->components->error('Set APP_KEY before production setup. Generate it once on the first installation and preserve it across deployments.');

            return self::FAILURE;
        }

        if ($this->call('key:generate') !== self::SUCCESS || blank(config('app.key'))) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
