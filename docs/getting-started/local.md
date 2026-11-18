# Local development

## Requirements

- PHP 8.3 or newer, with `pdo_pgsql`, `intl`, `zip`, `bcmath`, and `gd`.
- Composer.
- Node.js and npm. CI currently uses Node 26.
- PostgreSQL. CI uses PostgreSQL 17.

The application declares PHP 8.3 support. The test toolchain currently needs PHP 8.4 because Pest 5 requires it.

## Start the app

Create a local PostgreSQL database and user. `.env.example` uses
`content_machine` on `127.0.0.1:5432`. Set the database username and password
to a PostgreSQL role that exists on your local server.

```bash
# Fresh installations only; keep an existing .env.
cp .env.example .env
# Edit .env with your local database host, port, name, username, and password.
composer run setup
composer run dev
```

`composer run setup` installs locked dependencies, prepares a missing application
key without replacing an existing one, migrates the database, creates
`ADMIN_EMAIL` if configured, and builds the frontend.

`composer run dev` starts the Laravel server, Vite, logs when supported, the
default queue worker, scratchpad and PostSyncer workers, and scheduler. Open
the URL printed by Laravel, usually `http://localhost:8000`.

## First login and background workers

After migration, create an account at `/register`, or use `ADMIN_EMAIL`,
`ADMIN_NAME`, and `php artisan cm:ensure-admin` to create an owner account with
a generated password. Both supported setup paths create a team and default
workspace. The admin command skips existing accounts without repairing them or
resetting their passwords.

Follow the [README account setup](../../README.md#2-create-your-first-account)
for the exact commands and the
[development services](../../README.md#3-start-development-services) for the
processes started together by `composer run dev`. Use the separate supervised
worker commands from the [production guide](production.md) on a server.

## Dashboard 404: No current workspace

If login succeeds but `/dashboard` returns `404 No current workspace`, the
account has no resolvable active team/workspace. The current `DatabaseSeeder`
creates `test@example.com` without those records, so `php artisan db:seed` is
not a substitute for registration or the admin setup command.

For a fresh installation, use a new email with one of the supported setup paths
above. To keep an existing local account, repair its team/workspace through the
application's team-creation action. Run:

```bash
php artisan tinker
```

Paste the following block, replacing the email if needed. It preserves the
password and reuses an existing membership and workspace when possible:

```php
Illuminate\Support\Facades\DB::transaction(function () {
    $user = App\Models\User::where('email', 'test@example.com')
        ->lockForUpdate()
        ->firstOrFail();

    $team = $user->teams()->whereKey($user->current_team_id)->first()
        ?? $user->teams()->oldest('teams.id')->first();

    if ($team === null) {
        $team = app(App\Actions\Teams\CreateTeamAction::class)->handle(
            $user,
            App\Data\Teams\CreateTeamData::fromOwner($user),
        );
    }

    if (! $team->workspaces()->exists()) {
        $team->workspaces()->create(['name' => 'Default', 'slug' => 'default']);
    }

    $user->switchTeam($team);
});
```

Exit Tinker and refresh `/dashboard`. Creating a missing team through
`CreateTeamAction` also creates the owner membership and default workspace.
Running `cm:ensure-admin` for the same existing email will not perform this
repair. Change any default development password before making the app public.

## Run tests against a disposable PostgreSQL database

Tests use the `DB_CONNECTION` configured in `.env`. `phpunit.xml` intentionally
does not override it. Tests rely on PostgreSQL behavior such as JSONB, partial
indexes, constraints, and row locks. Point the test environment at a disposable
database, never a production or personal-data database.

CI uses PostgreSQL 17 with database `testing`, user `postgres`, password
`password`, and port `5432`. To use the same values locally, set these entries
in `.env` after starting PostgreSQL:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=testing
DB_USERNAME=postgres
DB_PASSWORD=password
```

Create that database and role locally if needed. Build the frontend assets before
running the suite so page tests can resolve the Vite manifest. Then run:

```bash
npm run build
php artisan migrate --force
php -d memory_limit=1G ./vendor/bin/pest
```

The 1 GB limit avoids exhausting PHP's common 128 MB local default in media tests.
CI runs Pest without `--parallel` because workers would race while migrating the
shared PostgreSQL schema. Run `./vendor/bin/pint --test` and
`./vendor/bin/phpstan analyse --memory-limit=1G`. For frontend checks, generate
route types first with `php artisan wayfinder:generate --with-form` (the build
also does this). Then run `npm run lint:check`, `npm run format:check`, and
`npm run types:check`. See [the exact workflow](../../.github/workflows/ci.yml).

## Optional integrations

The dashboard starts without external integrations. Configure Telegram, AI
providers, PostSyncer, and Google Drive from workspace Settings when you need
them. See [the API guide](../guides/api.md) and [the PostSyncer guide](../guides/postsyncer.md).
