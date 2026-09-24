# Local development

## Requirements

- PHP 8.3 or newer, with `pdo_pgsql`, `intl`, `zip`, `bcmath`, and `gd`.
- Composer.
- Node.js and npm. CI currently uses Node 26.
- PostgreSQL. CI uses PostgreSQL 17.

The application declares PHP 8.3 support. The test toolchain currently needs PHP 8.4 because Pest 5 requires it.

## Start the app

Create a local PostgreSQL database and user. `.env.example` uses
`content_machine` on `127.0.0.1:5432` with user `root` and no password. Adjust
those values for your local server.

```bash
composer install
npm ci
cp .env.example .env
# Edit .env with your local database host, port, name, username, and password.
php artisan key:generate
php artisan migrate
composer run dev
```

`composer run dev` starts the Laravel server, queue listener, and Vite dev
server. Open the URL printed by Laravel, usually `http://localhost:8000`.

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
