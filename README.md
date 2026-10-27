# Content Machine

Content Machine is a self-hosted, multi-workspace dashboard and API for capturing
ideas and managing posts and videos. It publishes through PostSyncer. It also
has Telegram capture, AI-assisted triage, Google Drive access, and a presentation
player for video decks.

[![CI](https://github.com/HarunRRayhan/content-machine/actions/workflows/ci.yml/badge.svg)](https://github.com/HarunRRayhan/content-machine/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)
![PHP](https://img.shields.io/badge/PHP-8.3%2B-777bb4)

## What runs here

- Laravel 13 app with a workspace-scoped dashboard and token-authenticated
  `/api/v1` endpoints.
- Scratch Pad capture from the dashboard and Telegram. Link resolution and voice
  transcription run in the queue.
- Ideas, posts, and videos stored in PostgreSQL. Posts and videos can be
  scheduled or published through workspace-configured PostSyncer accounts.
- Queued publishing with checkpoints and explicit recovery for uncertain
  PostSyncer operations.
- Google Drive integration for video exports and a presentation player for video
  decks.

See [the architecture overview](docs/architecture/overview.md), [the API
guide](docs/guides/api.md), and [the PostSyncer guide](docs/guides/postsyncer.md).

## Run locally

You'll need PHP 8.3+, Composer, Node.js, npm, and PostgreSQL. The test suite
currently needs PHP 8.4 because Pest 5 requires it. Follow [local
setup](docs/getting-started/local.md) for installation and database setup.

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
php artisan migrate
composer run dev
```

## Contributing

See [`CONTRIBUTING.md`](CONTRIBUTING.md) for project conventions and checks.
CI runs PHP tests against PostgreSQL 17. See [local
setup](docs/getting-started/local.md#run-tests-against-a-disposable-postgresql-database)
before running tests locally.

## Security

See [`SECURITY.md`](SECURITY.md) for how to report a vulnerability.

## License

[MIT](LICENSE.md)
