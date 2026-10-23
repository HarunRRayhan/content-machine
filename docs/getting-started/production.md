# Production setup

This guide covers a single-server installation. The repository also includes
Railway web and worker definitions under `deploy/`; those retain their Docker
build and `php artisan cm:deploy` pre-deploy hook.

## Requirements

- PHP 8.3+ with the extensions listed in the [local setup guide](local.md).
- Composer, Node.js, npm, and PostgreSQL. CI uses PHP 8.4, Node 26, and PostgreSQL 17.
- A web server with PHP-FPM, HTTPS, and the document root set to `public/`.
- A process supervisor such as Supervisor or systemd for workers and scheduling.
- Writable `storage/` and `bootstrap/cache/` directories for the runtime user.
- Persistent storage for `storage/app/uploads`, including across releases.

## Configure the first installation

Create the PostgreSQL database and role. On a fresh checkout, copy `.env.example`
to `.env` and configure it before running setup. Keep an existing environment
file on later releases. At minimum, set:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://content.example.com
DISABLE_REGISTRATION=true
ADMIN_EMAIL=you@example.com
ADMIN_NAME="Your Name"

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=content_machine
DB_USERNAME=content_machine
DB_PASSWORD=your_database_password

QUEUE_CONNECTION=database
POSTSYNCER_QUEUE_RETRY_AFTER=960
CACHE_STORE=database
SESSION_DRIVER=database
```

`ADMIN_EMAIL` creates an account with its own owner team and default workspace.
The command prints its generated password once; use it at `/login`, then change
it under account security. An existing account is left unchanged. Do not run
the development database seeder as an account-provisioning step.

Configure a real mail transport if you need password recovery or invitation
email delivery. The example environment logs mail instead of delivering it.

## Set the application key once

The production script requires `APP_KEY` to be provisioned beforehand. For the
first installation only, install the PHP dependencies and generate the key:

```bash
composer install --no-dev --optimize-autoloader --no-interaction
php artisan config:clear
php artisan key:generate --force
```

Store that key securely and reuse it on every release and every process. For
environment-variable deployments, provision the key through the host's secret
settings. Do not regenerate it on later deployments; saved provider, Telegram,
and PostSyncer credentials are encrypted with it.

## Prepare the release

With the environment and application key configured, run:

```bash
composer run setup:production
```

This command performs these steps and stops if any step fails:

1. Install locked PHP dependencies without development packages.
2. Clear cached configuration and require an existing application key.
3. Install locked Node dependencies, including the build tools, and build assets.
4. Run `cm:deploy` for migrations, upload-directory preparation, existing data
   maintenance, and optional first owner creation.
5. Cache application configuration, routes, and views with `artisan optimize`.

The script does not start the web server or supervise background processes.
Node development dependencies are needed to build Vite assets; they do not
change the `--no-dev` PHP installation.

The existing deployment command defers Telegram generation cutover migrations
in production unless the cutover is explicitly configured. Existing Telegram
deployments must follow the fleet-drain and preflight requirements in
`telegram:cutover-preflight --help` and `.env.example`. Preserve these gates;
do not replace `cm:deploy` with a blanket forced migration command.

## Supervise the runtime processes

Serve the application through your web server and PHP-FPM. Configure one
supervised process for each command below, using the project root as its working
directory and the same environment as the web application:

```bash
composer run worker:default
composer run worker:scratchpad
composer run worker:publish
composer run scheduler
```

These are four separate long-running processes, not a sequence to run in one
terminal. Configure automatic restarts and allow at least 960 seconds for
workers to finish during shutdown. Workers use a 900-second job timeout, and
the dedicated PostSyncer queue visibility window must exceed that timeout.

The scratchpad and publishing workers must be able to read the same persistent
uploads as the web process. For the existing Railway layout, these workers run
inside the web container, while the default worker and scheduler run in the
worker service. See the [PostSyncer deployment guide](../guides/postsyncer.md#queue-and-worker-railway).

Run a single scheduler per deployment. If your host already schedules
`php artisan schedule:run` every minute, use that instead of `composer run scheduler`.
Use `composer run dev` only for local development.

## Configure integrations and publish

Sign in, then follow the [integration setup](../../README.md#4-configure-integrations)
and [first-post workflow](../../README.md#5-generate-and-publish-your-first-post).
Telegram requires a public HTTPS webhook, and PostSyncer must be able to fetch
signed media URLs generated from `APP_URL`. Google Drive publishing files must
be publicly fetchable.

## Later releases

Back up the database and preserve the environment, application key, and uploads.
Prepare the new release with `composer run setup:production`, then activate it
using your host's deployment procedure. Restart PHP-FPM as appropriate and
restart all supervised workers and the scheduler so they load the new code and
configuration. Avoid overlapping schedulers during the switch.

For deployments using a separate build stage, build dependencies and assets
there and run the existing `php artisan cm:deploy` release hook against the
production database. The provided Railway Docker configuration already follows
that model; it does not need the combined single-server Composer setup script.
