# 0002: Keep operational/infra credentials out of the app's `.env`

## Status

Accepted

## Context

Setting up the Railway deployment needed a project-scoped API token, pasted
into the repo's root `.env` file — the natural place to put a secret in a
Laravel project. Shortly after, a local preview server needed its own
throwaway database config; the fastest way to stand that up was copying a
scratch env file over `.env`. That overwrote the Railway token with no
warning, because nothing distinguished "config the app reads at runtime"
from "a credential some unrelated CLI tool happens to also read out of the
same file."

The app's `.env` is going to keep getting overwritten, regenerated, and
swapped between local/testing/preview configurations for as long as this
project is developed against real infrastructure locally. Any credential
that isn't actually app configuration — a thing Laravel itself never reads
at request time — shouldn't be exposed to that churn.

## Decision

Operational and infrastructure credentials (the Railway CLI token today;
whatever else needs one later) live in `.secrets/<service>.env`, one file
per service, entirely separate from the app's own `.env`. The whole
directory is gitignored except a `README.md` and `*.env.example` templates.
See `.secrets/README.md`.

## Consequences

- A local test run can freely overwrite, regenerate, or delete the app's
  `.env` without any risk to infra credentials.
- Tooling that needs one of these credentials sources it explicitly
  (`export RAILWAY_TOKEN=$(grep ... .secrets/railway.env | cut -d'=' -f2-)`)
  rather than relying on it being present in the process environment by
  default — one extra line per script, in exchange for the isolation.
- A new service's credentials get their own `<service>.env` file rather
  than a shared one, so revoking or rotating one doesn't touch another.
