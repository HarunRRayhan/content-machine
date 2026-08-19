# `.secrets/`

Operational and infrastructure credentials — Railway tokens, and anything similar
added later — live here, one `<service>.env` file per service, plain `KEY=value`
lines like any other dotenv file.

**This is not the app's configuration.** The app reads its own `.env` at the repo
root (see `.env.example`), managed by Laravel/Artisan/Railway's deploy variables.
`.secrets/` holds credentials *tooling* uses to manage infrastructure from the
command line — the Railway CLI, and whatever else shows up later — kept out of
`.env` on purpose, so a local test run that overwrites `.env` (copying in a
throwaway config, switching between environments, etc.) can't take an unrelated
infra token down with it. That happened once already: a preview-server test
copied a scratch `.env` over the real one and wiped a pasted-in Railway token.

Every `*.env` file in this directory is gitignored (`/.secrets/*.env`). Only this
README and the `*.env.example` templates are tracked.

## Files

| File | Holds | Used for |
|---|---|---|
| `railway.env` | `RAILWAY_PROJECT_ID`, `RAILWAY_PROJECT_TOKEN` | `railway` CLI / API calls against the `cm.harun.dev` Railway project — deploy config, env vars, domains |

## Adding a new one

Copy the matching `.env.example` to `.env` in this directory and fill in real
values, e.g. `cp .secrets/railway.env.example .secrets/railway.env`. Add a new
`<service>.env.example` (committed, placeholder values only) plus a row in the
table above when a new tool needs its own credentials.

## Using `railway.env`

```bash
export RAILWAY_TOKEN=$(grep '^RAILWAY_PROJECT_TOKEN=' .secrets/railway.env | cut -d'=' -f2-)
railway status
```
