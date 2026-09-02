<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Throwable;

class AssertMigrationsReadyCommand extends Command
{
    protected $signature = 'cm:assert-migrations-ready';

    protected $description = 'Fail until all migrations required by workers have completed';

    public function handle(): int
    {
        try {
            /** @var Migrator $migrator */
            $migrator = $this->laravel->make('migrator');

            if (! $migrator->repositoryExists()) {
                $this->error('The migration repository does not exist yet.');

                return self::FAILURE;
            }

            $ran = $migrator->getRepository()->getRan();
            $files = $migrator->getMigrationFiles(
                array_merge($migrator->paths(), [database_path('migrations')]),
            );
            $pending = array_diff(array_keys($files), $ran);

            if (app()->environment('production') && ! config('app.telegram_cutover_ready')) {
                $deferred = array_map(
                    fn (string $migration): string => pathinfo($migration, PATHINFO_FILENAME),
                    DeployCommand::TELEGRAM_CUTOVER_MIGRATIONS,
                );
                $pending = array_diff($pending, $deferred);
            }

            if ($pending !== []) {
                $this->error('Pending migrations: '.implode(', ', $pending));

                return self::FAILURE;
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Could not verify migration readiness: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
