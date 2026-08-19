# Contributing to content-machine

Thanks for taking a look at this project. It's early: a fresh Laravel + Inertia +
React scaffold, pre-alpha, nothing is wired up yet. That's actually a good time to
get involved if you want to help shape the foundations.

## Local development

There's no `compose.yaml` yet (that lands once the app actually needs Postgres/S3
services locally), so for now everything runs against tools installed on your own
machine:

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
composer run dev
```

`composer run dev` starts the Laravel server, the queue listener, and the Vite dev
server together. You'll need PHP 8.3+ and a local Postgres instance (see
`.env.example` for the connection defaults it expects).

Once a `compose.yaml` exists, this section will be updated to point at `docker
compose up` instead.

## Branch naming

Branch off `main`. Prefix with what the change is:

- `feat/short-description` for a new feature
- `fix/short-description` for a bug fix
- `chore/short-description` for anything else (deps, CI, docs)

## Before opening a PR

Run the checks CI will run:

```bash
./vendor/bin/pint --test        # PHP code style
./vendor/bin/phpstan analyse    # static analysis
composer audit                  # dependency vulnerabilities
npm run lint:check              # ESLint
npm run format:check            # Prettier
npx tsc --noEmit                # TypeScript
npm run build                   # production build
./vendor/bin/pest --parallel    # tests
```

`composer run test` covers the PHP side of that list (style, static analysis,
tests) in one command. There isn't a single combined script for everything yet.

If Pint or Prettier flag something, let them fix it for you:

```bash
./vendor/bin/pint
npm run format
```

## What a good PR looks like

- One logical change per PR. If you're fixing a bug and also refactoring
  something unrelated, split it.
- Tests for new behavior, or an explanation in the PR description of why none
  are needed.
- CI green before requesting a review. A red PR won't get looked at until it's
  fixed.

## Database migrations

Migrations here need to stay additive-only: expand first, contract later. Don't
drop or rename a column in the same PR that stops using it. This project is
meant to deploy without downtime once it's live, and a migration that both adds
new usage and removes old usage in one shot breaks that during the rollout
window. If you need to retire a column, that's two PRs: stop reading/writing it,
ship, wait, then drop it.

## Questions

Open an issue, or start a discussion if the repo has Discussions enabled. There's
no other contact channel for this project yet.
