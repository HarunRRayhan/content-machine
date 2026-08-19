# content-machine

An open-source, self-hosted content pipeline: capture ideas anywhere, then write and schedule posts across platforms from one dashboard.

[![CI](https://github.com/HarunRRayhan/content-machine/actions/workflows/ci.yml/badge.svg)](https://github.com/HarunRRayhan/content-machine/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)
![PHP](https://img.shields.io/badge/PHP-8.3%2B-777bb4)

## What this is

content-machine replaces a private, markdown-file pipeline (a folder of
scratch notes and a lot of manual scheduling) with a real multi-user app.
Capture an idea the moment it hits you, starting with a Telegram bot, write
it up, and schedule it across platforms. A database instead of a folder of
files.

## Status

Pre-alpha. This is a fresh scaffold, not a working product. No Telegram bot,
no publishing, no scheduling, none of it built yet. See [`docs/`](docs/) for
the architecture and roadmap as they take shape.

## Getting started

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
composer run dev
```

`composer run dev` runs the app server, queue listener, and Vite dev server
together. You'll need a local Postgres instance, see `.env.example` for the
connection settings it expects. More detail in
[`CONTRIBUTING.md`](CONTRIBUTING.md).

## Tech stack

- Laravel 13
- Inertia 3
- React 19 + TypeScript
- Tailwind CSS 4
- PostgreSQL

## Contributing

See [`CONTRIBUTING.md`](CONTRIBUTING.md) for local setup, branch naming, and
what CI checks before a PR can merge.

## Security

See [`SECURITY.md`](SECURITY.md) for how to report a vulnerability.

## License

[MIT](LICENSE.md)
