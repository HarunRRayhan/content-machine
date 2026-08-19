<?php

namespace App\Console\Commands;

use App\Actions\Users\EnsureAdminUserAction;
use Illuminate\Console\Command;

/**
 * Seeds the first (or an additional) user on a self-hosted instance where
 * DISABLE_REGISTRATION has closed the sign-up form. Reads ADMIN_EMAIL /
 * ADMIN_NAME from the environment rather than taking arguments, so it can
 * run unattended as part of a deploy's preDeployCommand — a no-op on every
 * run except the one where the account doesn't exist yet.
 */
class EnsureAdminUserCommand extends Command
{
    protected $signature = 'cm:ensure-admin';

    protected $description = 'Create the ADMIN_EMAIL user (with a personal team) if it does not already exist';

    public function handle(EnsureAdminUserAction $action): int
    {
        $email = config('app.admin_email');

        if (! $email) {
            $this->components->info('ADMIN_EMAIL not set, skipping.');

            return self::SUCCESS;
        }

        $result = $action->handle($email, config('app.admin_name'));

        if ($result === null) {
            $this->components->info("Admin user {$email} already exists, skipping.");

            return self::SUCCESS;
        }

        $this->components->info("Created admin user {$email}.");
        $this->line("  Password: {$result['password']}");
        $this->components->warn('Log in and change this password immediately — it is printed here once, in the deploy log, and nowhere else.');

        return self::SUCCESS;
    }
}
