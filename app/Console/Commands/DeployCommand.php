<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
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
    /**
     * These migrations change Telegram idempotency scope and therefore belong
     * to the separately verified old-fleet cutover, not a rolling deploy.
     *
     * @var list<string>
     */
    public const TELEGRAM_CUTOVER_MIGRATIONS = [
        '2026_09_02_000005_scope_telegram_outbound_logical_keys_to_generation.php',
        '2026_09_02_000006_drop_legacy_telegram_update_unique_index.php',
    ];

    protected $signature = 'cm:deploy';

    protected $description = 'Run pending migrations and ensure the admin user exists (preDeployCommand entry point)';

    public function handle(): int
    {
        if (! $this->ensureScratchpadUploadsDirectory()) {
            return self::FAILURE;
        }

        if (app()->environment('production', 'prod') && config('app.telegram_cutover_ready')) {
            if ($this->call('migrate', $this->migrationOptionsWithoutCutover()) !== self::SUCCESS
                || $this->call('telegram:cutover-preflight', [
                    '--require-fleet-drained' => true,
                    '--verify-remote-webhooks' => true,
                ]) !== self::SUCCESS
                || $this->call('migrate', $this->cutoverMigrationOptions()) !== self::SUCCESS
            ) {
                return self::FAILURE;
            }
        } elseif ($this->call('migrate', $this->migrationOptions()) !== self::SUCCESS) {
            return self::FAILURE;
        }

        foreach (['cm:requeue-legacy-media-jobs', 'posts:backfill-templates', 'cm:ensure-admin'] as $command) {
            if ($this->call($command) !== self::SUCCESS) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /**
     * Keep the contract migrations pending until the operator has drained the
     * old web fleet and reconciled legacy Telegram rows.
     *
     * @return array<string, mixed>
     */
    private function migrationOptions(): array
    {
        $options = ['--force' => true];

        if ($this->canIsolateMigrations()) {
            $options['--isolated'] = true;
        }

        if (! app()->environment('production', 'prod') || config('app.telegram_cutover_ready')) {
            return $options;
        }

        $options = $this->migrationOptionsWithoutCutover();

        $this->warn('Telegram generation cutover migrations are deferred until CM_TELEGRAM_CUTOVER_READY=true.');

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    private function migrationOptionsWithoutCutover(): array
    {
        $paths = glob(database_path('migrations/*.php')) ?: [];
        $paths = array_values(array_filter(
            $paths,
            fn (string $path): bool => ! in_array(basename($path), self::TELEGRAM_CUTOVER_MIGRATIONS, true),
        ));

        $options = [
            '--force' => true,
            '--path' => $paths,
            '--realpath' => true,
        ];

        if ($this->canIsolateMigrations()) {
            $options['--isolated'] = true;
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    private function cutoverMigrationOptions(): array
    {
        $options = [
            '--force' => true,
            '--path' => array_map(
                fn (string $migration): string => database_path('migrations/'.$migration),
                self::TELEGRAM_CUTOVER_MIGRATIONS,
            ),
            '--realpath' => true,
        ];

        if ($this->canIsolateMigrations()) {
            $options['--isolated'] = true;
        }

        return $options;
    }

    private function canIsolateMigrations(): bool
    {
        if (config('cache.default') !== 'database') {
            return true;
        }

        $lockTable = config('cache.stores.database.lock_table') ?: 'cache_locks';

        return Schema::hasTable((string) $lockTable);
    }

    /**
     * The scratchpad disk root is storage/app/uploads, which is also the
     * Railway volume mount. The directory is gitignored, so it is not in
     * the image, and a fresh volume is owned by root. Create it here so
     * the first photo/post-image write does not have to mkdir under a
     * root-owned mount as www-data.
     */
    private function ensureScratchpadUploadsDirectory(): bool
    {
        $root = Storage::disk('scratchpad')->path('');

        if (! is_dir($root) && ! mkdir($root, 0775, true) && ! is_dir($root)) {
            $this->warn("Could not create scratchpad uploads directory at {$root}");

            return false;
        }

        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            @chown($root, 'www-data');
            @chgrp($root, 'www-data');
        }

        return is_dir($root) && is_writable($root);
    }
}
