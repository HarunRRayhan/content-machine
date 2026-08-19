<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Infra readiness check. Only reports on what actually exists in this
 * phase (database, migrations, storage) — the "not configured" rows are
 * deliberately honest placeholders for infra later phases will add
 * (mail, Telegram, AI/transcription), not stubs pretending they work.
 */
class DoctorCommand extends Command
{
    protected $signature = 'cm:doctor';

    protected $description = 'Check infrastructure readiness for the current phase';

    public function handle(): int
    {
        [$databaseOk, $databaseDetail] = $this->checkDatabase();
        [$migrationsOk, $migrationsDetail] = $this->checkMigrations();
        [$storageOk, $storageDetail] = $this->checkStorage();

        $this->table(['Check', 'Status', 'Detail'], [
            ['Database', $this->status($databaseOk), $databaseDetail],
            ['Migrations', $this->status($migrationsOk), $migrationsDetail],
            ['Storage (local)', $this->status($storageOk), $storageDetail],
            ['Mail', 'not configured', 'skipped — no mailer wired up yet'],
            ['Telegram', 'not configured', 'skipped — Phase 1'],
            ['AI / transcription', 'not configured', 'skipped — Phase 1'],
        ]);

        $healthy = $databaseOk && $migrationsOk && $storageOk;

        return $healthy ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function checkDatabase(): array
    {
        try {
            $connection = DB::connection();
            $driver = $connection->getDriverName();
            $version = $this->databaseVersion($driver);

            return [true, trim("{$driver} {$version}")];
        } catch (Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    private function databaseVersion(string $driver): string
    {
        return match ($driver) {
            'pgsql' => $this->extractVersionNumber(
                DB::selectOne('select version() as version')->version ?? '',
                '/PostgreSQL\s+(\S+)/i',
            ),
            'mysql', 'mariadb' => (string) (DB::selectOne('select version() as version')->version ?? 'unknown'),
            'sqlite' => (string) (DB::selectOne('select sqlite_version() as version')->version ?? 'unknown'),
            default => 'unknown',
        };
    }

    private function extractVersionNumber(string $raw, string $pattern): string
    {
        if (preg_match($pattern, $raw, $matches) === 1) {
            return $matches[1];
        }

        return $raw ?: 'unknown';
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function checkMigrations(): array
    {
        try {
            /** @var Migrator $migrator */
            $migrator = $this->laravel->make('migrator');

            $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];

            $files = $migrator->getMigrationFiles(
                array_merge($migrator->paths(), [database_path('migrations')]),
            );

            $pending = array_diff(array_keys($files), $ran);

            if (count($pending) === 0) {
                return [true, 'up to date'];
            }

            return [false, count($pending).' pending'];
        } catch (Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function checkStorage(): array
    {
        $disk = Storage::disk(config('filesystems.default'));
        $path = 'cm-doctor-'.Str::random(12).'.txt';
        $contents = (string) Str::uuid();

        try {
            $disk->put($path, $contents);

            if ($disk->get($path) !== $contents) {
                return [false, 'wrote a file but read back different contents'];
            }

            $disk->delete($path);

            return [true, 'write+read+delete ok'];
        } catch (Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    private function status(bool $ok): string
    {
        return $ok ? 'ok' : 'fail';
    }
}
