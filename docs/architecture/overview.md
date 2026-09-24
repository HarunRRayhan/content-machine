# Architecture overview

Content Machine is a Laravel 13 application with an Inertia 3, React 19, and
TypeScript dashboard. PostgreSQL stores workspace data. Laravel Actions own
writes, controllers handle HTTP boundaries, and queued jobs run work that should
not block a request.

## Data and workspace boundaries

Each team has one or more workspaces. Dashboard requests select a current
workspace through `SetCurrentWorkspace`. API tokens belong to one workspace.
Queries and writes must stay inside that workspace. PostgreSQL is the source of
truth for Scratch Pad entries, ideas, posts, videos, publish state, and workspace
settings.

Uploaded files are represented by workspace-owned `MediaAsset` records and
attached to content with `Attachment` records. Video decks have a manifest used
by the presentation player. Google Drive links are stored for publishable media
that remains in Drive.

## Request paths

- Authenticated dashboard pages use Inertia and React. Posts, videos, Scratch
  Pad, ideas, team settings, and integration settings have routes and controllers
  under `routes/` and `app/Http/Controllers/`.
- Workspace-token clients use `/api/v1`. The API exposes Scratch Pad, ideas,
  posts, videos, media, Google Drive, and publishing/recovery operations. See the
  [API guide](../guides/api.md) for current endpoints and remaining gaps.
- `/mcp` exposes workspace-token tools over Streamable HTTP for supported content operations.

Writes follow the Action pattern documented in [`CLAUDE.md`](../../CLAUDE.md).
Controllers validate and translate requests. Actions own the operation, and API
Resources define serialized records.

## Background work and publishing

Laravel's database queue backs asynchronous capture, transcription, and
publishing. Posts and videos use `PublishPostJob` and `PublishVideoJob`. The
publish actions plan PostSyncer groups, register media by URL, and checkpoint
progress before external creates. Retries skip completed groups. An operator
must reconcile uncertain external results before retrying. The [PostSyncer
guide](../guides/postsyncer.md) documents queue deployment, recovery, and
scheduling.

The scheduled `postsyncer:sync-scheduled` command refreshes eligible records from
PostSyncer. It is separate from publish-job recovery and does not call PostSyncer
from dashboard requests.

## Main code locations

- `app/Actions/`: write operations.
- `app/Jobs/`: queued work.
- `app/Models/`: Eloquent records and relations.
- `app/Http/Controllers/`: dashboard and API request adapters.
- `app/Support/`: framework and integration helpers.
- `resources/js/`: Inertia pages and React components.
- `routes/`: dashboard, settings, API, posts, videos, and console routes.
- `database/migrations/`: additive schema changes.

For local setup, including the PostgreSQL test database used in CI, see [Local development](../getting-started/local.md).
