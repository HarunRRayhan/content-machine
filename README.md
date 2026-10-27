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

## Setup and usage

Follow these steps to go from a fresh checkout to your first published post.
The dashboard works without external integrations; AI generation, Telegram,
Google Drive, and publishing each need their own configuration.

### 1. Install and start locally

You need PHP 8.3+ with `pdo_pgsql`, `intl`, `zip`, `bcmath`, and `gd`, Composer,
Node.js, npm, and PostgreSQL. The current Pest 5 test suite needs PHP 8.4.
CI uses PostgreSQL 17 and Node 26.

From the project directory:

```bash
# Fresh installations only: keep your existing .env if you already have one.
cp .env.example .env
```

Create a PostgreSQL database and database user, then edit `.env` to match them:

```dotenv
APP_URL=http://127.0.0.1:8000
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=content_machine
DB_USERNAME=your_database_user
DB_PASSWORD=your_database_password
QUEUE_CONNECTION=database
```

Then initialize the application:

```bash
composer run setup
composer run dev
```

`composer run setup` installs PHP and Node dependencies from their lockfiles,
creates `.env` if missing, generates `APP_KEY` only when missing, runs migrations,
creates `ADMIN_EMAIL` if configured, and builds the frontend. Existing encrypted
integration credentials depend on preserving the same application key.
Open the URL printed by Laravel, usually <http://127.0.0.1:8000>, and keep the
development process running.
See [local development](docs/getting-started/local.md) for more details.

### 2. Create your first account

Choose one of these methods. Both create an account with its own team and
default workspace, which the dashboard requires.

**Register in the browser:** leave `DISABLE_REGISTRATION=false` in `.env`, open
`/register`, and create your account. Successful registration takes you to
`/dashboard`.

**Create an owner account from the terminal:** add these values to `.env`:

```dotenv
ADMIN_EMAIL=you@example.com
ADMIN_NAME="Your Name"
```

In a separate terminal, from the project directory, run:

```bash
php artisan config:clear
php artisan cm:ensure-admin
```

The command prints a generated password once. Sign in at `/login`, open
`/dashboard`, then change the password under account security. The account owns
its team; there is no separate global admin flag to enable.

If the command says the account already exists, it leaves that account
unchanged. It does not reset its password or repair its workspace.

After creating your account, you can close public registration:

```dotenv
DISABLE_REGISTRATION=true
```

Run `php artisan config:clear` after changing that setting.

Do not use `php artisan db:seed` as the first-account setup. The current seeder
creates `test@example.com` without a team or workspace, which causes a dashboard
404. See [recovering an account with no workspace](docs/getting-started/local.md#dashboard-404-no-current-workspace)
if you already used that account.

### 3. Start development services

`composer run dev` starts the Laravel server, Vite, logs when supported, the
default queue worker, the scratchpad worker, the PostSyncer worker, and the
scheduler together. Keep it running while using the app; no extra worker
terminals are needed for local development. Restart it after changing worker
code or configuration.

The scratchpad worker handles Telegram media, transcription, and AI post
generation. The PostSyncer worker handles post and video publishing. The
scheduler recovers pending work and refreshes published status. The database
queue is enough to start; Redis is not required.

For production, use the separate supervised processes in the
[production setup guide](docs/getting-started/production.md), rather than the
development server.

### 4. Configure integrations

Integration settings belong to the current workspace. Configure them while
signed in as its owner.

| Integration | Setup | Enables |
|---|---|---|
| AI Providers | Open `/settings/ai-providers`. Add an enabled provider with its API key, then add a model under Models > Default. Add a suitable model under Vision for photo input. | AI post drafts, summaries, and triage suggestions. Saving a key alone does not add a model to the generation chain. |
| Telegram | Open `/settings/telegram`. Connect a bot token, get a link code, and send `/link CODE` to that bot from your Telegram account. | Capture and the draft > approve > publish workflow. Each member links their own Telegram account. |
| PostSyncer | Connect your social accounts in PostSyncer. In `/settings/postsyncer`, save its API key. In `/settings/postsyncer/workspaces`, select language workspaces and activate the desired accounts. Enable publishing when ready. | Scheduling and delivery to the configured social accounts. |
| Google Drive (optional) | Configure Google OAuth in `.env`, then connect through `/settings/google-drive` and select an export folder. | Browse and select finished video files and covers from Drive. Public Drive links can also be pasted directly. |

AI providers support OpenAI and Anthropic request formats, including compatible
custom base URLs. Voice transcription additionally needs a compatible
`/audio/transcriptions` endpoint; a text or vision model alone is insufficient.

Telegram needs a publicly reachable HTTPS webhook. A bot cannot deliver updates
to `127.0.0.1`; for local testing, expose the app through an HTTPS tunnel and set
`APP_URL` to that public URL before connecting the bot. PostSyncer also needs to
fetch the app's signed attachment URLs, so local uploaded-media publishing needs
public reachability too. Clear cached configuration after changing `APP_URL`.

See the [Telegram guide](docs/guides/telegram-bot.md) and
[PostSyncer/Google Drive guide](docs/guides/postsyncer.md) for detailed setup.

### 5. Generate and publish your first post

With an AI model, linked Telegram account, and PostSyncer configured, send this
to your bot:

```text
/post English: Our website maintenance service includes weekly backups, security updates, and monthly reports.
```

The bot saves the source, generates a draft with Facebook and Instagram
captions, and sends a preview. Review the draft in Content Machine and edit
its content or captions before approving it. Use the actual ID from the preview:

```text
/approve P-123
```

To publish immediately:

```text
/post_now P-123
```

To schedule, use a future date and time in the workspace timezone instead:

```text
/schedule P-123 YYYY-MM-DD HH:MM
```

Replace the date/time placeholders with actual values. Scheduling defaults to
Asia/Dhaka for a workspace using the default timezone. Generation never
publishes automatically; generated drafts require approval first.

For photo-based content, send `/post`, then a photo, or put `/post` at the start
of the photo caption. For spoken content, send `/post`, then a voice note or
audio file. Request English or Bangla explicitly; configured language workspaces
can also drive bilingual generation. Use `/cancel` to cancel an active request.

You can also open the post in the dashboard and select **Schedule** or
**Publish now**. Available platforms depend on the post's platforms, active
PostSyncer accounts, and post-type rules. Instagram needs suitable media;
text-only publishing is available only where the configured platform rules
allow it. Every caption language needs its own configured PostSyncer workspace.

### 6. Work through the dashboard or publish videos

The dashboard workflow is **Scratch Pad > post/video idea > draft > ready >
scheduled > posted/published**. Capture a note or link in Scratch Pad, optionally
use **Suggest with AI**, file it as an idea, and promote the idea to a draft.
Promotion copies the idea's title and body into a draft shell; it does not write
a complete AI post. Use Telegram `/post` for built-in draft generation, or write
content yourself and edit it in the dashboard.

For videos, use Content Machine to manage ideas, scripts, series, presentation
decks, and captions. Record and edit the final video with your production tools,
then upload it to Google Drive. On the video page, set **Video Drive URL** and
optionally **Cover Drive URL**, select platforms, and schedule or publish.
These must be public file links, not private files or folders. The Drive picker
can grant Anyone-with-the-link access when selecting publishing media. Content
Machine does not generate a finished video file from a prompt.

### 7. Connect external tools

Open **Team > API access**, create a workspace token with the abilities your
client needs, and save the token when shown. Use it as a bearer token for the
REST API at `/api/v1` or the Streamable HTTP MCP endpoint at `/mcp`.

The REST API can create and update content, attach media, approve posts, and
queue publishing. MCP tools can capture notes, triage ideas, update existing
posts/videos, and publish them. An external writing tool can generate content
and save drafts through the REST API for review. See the
[API guide](docs/guides/api.md) for endpoints, permissions, and client setup.
For API/MCP publishing, omitting `when` means publish immediately; always pass
`when` when scheduling.

### Troubleshooting

| Symptom | What to check |
|---|---|
| Login succeeds but `/dashboard` returns 404 | The account needs an active team and workspace. See the [account recovery steps](docs/getting-started/local.md#dashboard-404-no-current-workspace). |
| Admin command skips the account | `ADMIN_EMAIL` already exists. Use its existing password or password recovery; the command does not reset or repair it. |
| `/register` returns 403 | Set `DISABLE_REGISTRATION=false` and clear cached configuration, or create an owner through `cm:ensure-admin`. |
| AI draft generation fails | Enable a provider and add a model to the Default or Vision chain, as appropriate. Check that the scratchpad process in `composer run dev` is running. |
| Telegram does not respond | Check public HTTPS reachability, bot connection, your account link, and both the default and scratchpad workers. |
| Schedule/Publish is unavailable | Check the PostSyncer key, language workspaces, active accounts, publish toggle, approval, platform rules, and required media. |
| A publish remains queued | Keep `composer run dev` running locally; in production, supervise `composer run worker:publish`. |
| A publish has an uncertain outcome | Follow [PostSyncer recovery](docs/guides/postsyncer.md) before retrying; do not create a second copy blindly. |

## Production setup

Configure the production environment and database first, then run
`composer run setup:production`. This installs PHP dependencies without dev
packages, requires an existing `APP_KEY`, builds the frontend, runs the existing
`cm:deploy` deployment steps, and caches the application. It preserves the
application key and creates `ADMIN_EMAIL` only when that account does not exist.

Use PHP-FPM with a web server and supervise the queue workers and scheduler.
See [production setup](docs/getting-started/production.md) for the environment,
first key, process commands, uploads, and repeat deployments. The existing
Railway configuration continues to use its Docker build and `cm:deploy` hook.

## Composer commands

| Command | Purpose |
|---|---|
| `composer run setup` | Install and initialize a local checkout without rotating an existing key. |
| `composer run dev` | Start the complete local development workflow. |
| `composer run setup:production` | Install and prepare a production release with a preconfigured environment and key. |
| `composer run worker:default` | Run the default queue worker. |
| `composer run worker:scratchpad` | Run media, transcription, and generation jobs. |
| `composer run worker:publish` | Run PostSyncer publishing jobs. |
| `composer run scheduler` | Run the Laravel scheduler. |

## Contributing

See [`CONTRIBUTING.md`](CONTRIBUTING.md) for project conventions and checks.
CI runs PHP tests against PostgreSQL 17. See [local
setup](docs/getting-started/local.md#run-tests-against-a-disposable-postgresql-database)
before running tests locally.

## Security

See [`SECURITY.md`](SECURITY.md) for how to report a vulnerability.

## License

[MIT](LICENSE.md)
