# PostSyncer

Content Machine configures PostSyncer per workspace and schedules or publishes
**posts** from the dashboard. Video publishing is temporarily disabled until
safe retries and reconciliation are available. PostSyncer still delivers to Facebook,
Instagram, TikTok, YouTube, and the other connected accounts; CM only
orchestrates.

After cutover, Script Studio schedules through Content Machine. CM owns the
PostSyncer key and the publish job. Local `/api/posts/publish` should call
`POST /api/v1/posts/{human_id}/publish` (or the MCP `publish_post` tool), not
PostSyncer directly. Set `CONTENT_MACHINE_PUBLISH=1` on the Script Studio server
once that proxy is live (see personal-content `docs/postsyncer-setup.md`).

## Queue and worker (Railway)

Publishing is already a queued job. Do not add Redis unless the database
queue starts to hurt.

| Piece | Where | Role |
|---|---|---|
| `jobs` table | `cm-db` (Postgres) | Laravel `database` queue. `QUEUE_CONNECTION` is unset on Railway, so this is the default. |
| `PublishPostJob` | `cm-web` `postsyncer` connection, `postsyncer` queue | Same volume constraint as Telegram capture: attachment covers live under `storage/app/uploads`, mounted only on `cm-web`. The job resolves signed media URLs and must see those files. |
| `PublishVideoJob` | disabled | Video publishing is blocked until durable retry and reconciliation support is complete. |
| `cm-worker` | Railway worker service | `queue:work --queue=default` plus `schedule:work` via supervisord. |
| `cm-web` scratchpad worker | same web container (`/init`; `SCRATCHPAD_QUEUE_WORKER=0` disables it) | s6-supervised `queue:work --queue=scratchpad` for Telegram photo/voice and voice transcription. Those jobs read/write `storage/app/uploads`, and that volume is mounted only on `cm-web`. Telegram text, links, and commands use `cm-worker`'s supervised `default` queue. |
| `cm-web` PostSyncer worker | same web container (`/init`; follows `SCRATCHPAD_QUEUE_WORKER`) | s6-supervised `queue:work postsyncer --queue=postsyncer --timeout=900` for post publishes. |
| `postsyncer:sync-scheduled` | every five minutes | Pulls PostSyncer status back onto scheduled records. |

`POST /api/v1/posts/{human_id}/publish` and the dashboard Schedule/Publish
buttons use the same enqueue path. Redis is optional later; it is not a
cutover blocker.

`PublishPostJob` has a 900-second timeout and the dedicated `postsyncer`
database queue has a 960-second visibility window. Its row lease and overlap
lock make duplicate dispatches harmless, while leaving the scheduled recovery
sweep free to re-enqueue a job if queue insertion fails.

## Settings → PostSyncer

Workspace owners configure PostSyncer from the Settings submenu. The
PostSyncer page has two tabs: **General** (`/settings/postsyncer`) for
the API key, then **Workspaces** (`/settings/postsyncer/workspaces`)
for language workspaces and accounts. Pick a default language workspace
(English by default) and add extra language workspaces only if you need
them. The old `/dashboard/settings/postsyncer` URL redirects to General.

| Field | Purpose |
|---|---|
| **API key** | Write-only after save. Rotated by pasting a new key. Encrypted at rest. |
| **API base** | Default `https://postsyncer.com/api/v1` |
| **Upload base** | Default `https://upload.postsyncer.com/api/v1` (reserved for optional file upload fallback; v1 uses link upload on the API base) |
| **Publish enabled** | Cutover flag. When off, Schedule/Publish buttons stay hidden even if the API key is set. Turn on only after settings validate and you are ready to move off Script Studio. |
| **Default language** | The workspace used for publishing. English if unset. |
| **Extra language workspaces** | Optional. Add Bangla or English only if you post in that language. |
| **Workspace** | Picker shows `Name (id)`. The account list stays hidden until you pick one. |
| **Accounts** | Pulled from that workspace only. Handle and platform, plus which post types Script Studio allows. No manual id or handle entry. |
| **Active** | Turn on the handles you want to use. Missing platforms stay off. |
| **Post-type rules** | Copied from Script Studio and saved with the page. Shown as a short type list on each pulled handle, not as a blank matrix. |
| **Refresh** | Reloads accounts for the workspace currently selected in the dropdown. |
| **Connection** | The Workspaces page says whether PostSyncer returned workspaces for the saved API key. |

Post Schedule and Publish stay disabled until the API key is set, the default
language workspace id is filled, and **Publish enabled** is on. Video Schedule
and Publish remain disabled independently.

## PostSyncer API hosts (confirmed)

All authenticated calls use `Authorization: Bearer <api_key>`.

| Operation | Host + path |
|---|---|
| List workspaces | `GET https://postsyncer.com/api/v1/workspaces` (id + name for the picker) |
| List accounts | `GET https://postsyncer.com/api/v1/accounts` (filter client-side by `workspace_id`) |
| Link-upload media | `POST https://postsyncer.com/api/v1/media/upload/url` with `{ "workspace_id", "urls": ["…"] }` |
| Create / schedule post | `POST https://postsyncer.com/api/v1/posts` |
| File upload (legacy / fallback) | `POST https://upload.postsyncer.com/api/v1/media/upload/file` on the **upload** subdomain, not the main API host |

CM registers Google Drive or attachment URLs through the link-upload endpoint,
then passes returned media ids into `POST /posts`.

### First comments (Facebook / Instagram / LinkedIn / YouTube)

When a caption group has a non-empty `first_comment`, `PublishPostAction` appends a
second `content` item:

```json
{ "text": "…", "is_first_comment": true, "first_comment_delay": 1 }
```

The planner keeps those platforms in their own PostSyncer group so Threads /
Twitter / Bluesky never receive the extra content item (PostSyncer may treat it
as a thread). Named cover images that fail to resolve refuse the publish instead
of falling through as text-only.

## Seed from personal-content

One-time import from the old Script Studio JSON:

```bash
php artisan postsyncer:seed {workspace_id} \
  --workspaces=/path/to/personal-content/web/workspaces.json \
  --post-types=/path/to/personal-content/web/post_types.json \
  --api-key="$POSTSYNCER_API_KEY"
```

This writes encrypted `api_key`, Bangla/English platform maps, post-type rules,
and sets `publish_enabled` to **true**. Review Settings → PostSyncer before
scheduling live content.

## Schedule / Publish on show pages

Open a **post** or **video** from Posts or Videos. When settings are ready and
**Publish enabled** is on, the detail page shows **Schedule** and **Publish
now**.

The dialog lets you pick:

- **When** — datetime in the workspace timezone (default Asia/Dhaka), or Now
- **Platforms** — intersection of the record's platforms, configured accounts,
  and post-type rules
- **Ask platforms** — English Twitter/Threads/Bluesky photo posts need an
  explicit confirm checkbox (same gate as Script Studio)

Bilingual posts auto-split into separate PostSyncer calls per language,
Twitter-thread isolation, and media-set grouping (ported from personal-content
`publish_post()`). All group ids land in the record's `postsyncer.groups` JSON.

While a job runs, `publish_state` shows `queued` or `running` and buttons
disable. On success the pipeline status moves to **Scheduled** (future time) or
**Posted** / **Published** (publish now). On failure, status stays unchanged,
`publish_state` is `failed`, and **Retry** re-runs the full plan.

## Drive URL fields

### Videos

On the video show page, before scheduling:

- **Video Drive URL** (`video_drive_url`) — required. A live Google Drive file
  share link. CM checks that Anyone with the link can fetch it, then sends
  PostSyncer the download form (`drive.usercontent.google.com/download?id=…`).
  A folder link or a private file is rejected.
- **Cover Drive URL** (`cover_drive_url`) — optional. Same public-file rule.
  Used as `content[0].cover_image` for YouTube / Instagram / Facebook.

Paste in the video overview, or push from local via
`PATCH /api/v1/videos/{human_id}` / MCP `update_video`.
`POST /api/v1/media-urls/check` and MCP `check_drive_url` probe a link
without saving it.

### Posts

- Prefer **attachments** uploaded in CM (public/signed URLs sent to link-upload).
- Otherwise **Image Drive URLs** (`image_drive_urls`, one per line) for carousel
  or photo posts without attachments.

Text-only posts schedule when the post-type matrix allows it for the selected
platforms.

### Reconcile an uncertain create

If a worker loses the response after `POST /posts`, first find the created post
in the matching PostSyncer workspace. Verify its content, media, platforms,
and schedule against the failed operation, then run this on the Content Machine
deployment:

```bash
php artisan postsyncer:reconcile-post WORKSPACE_ID P-68 POSTSYNCER_POST_ID
```

The command calls `GET /posts/{id}` and only records the id when the workspace
and group payload match. Retry the post afterwards; the reconciled group is
checkpointed and won't be created again.

## Status tabs

List pages mirror Script Studio's pipeline tabs with live counts.

**Posts:** Ideation · Draft · Ready · Scheduled · Posted · Archived · Dropped
(default **Draft**). Ideation lists open post ideas (`kind = post`).

**Videos:** Ideation · Draft · Pending · Ready · Recorded · Scheduled ·
Published · Archived · Dropped (default **Pending**). Ideation lists open video
ideas. The Posted stage is labeled **Published** in the UI.

Search and language filters apply on every tab except Ideation.

## Cutover checklist

1. Run `postsyncer:seed` (or fill Settings manually) and confirm **Refresh
   accounts** looks correct.
2. Set **Publish enabled** on in CM Settings → PostSyncer.
3. Schedule one draft **post** through `POST /api/v1/posts/{human_id}/publish`
   with a future `when` (never omit `when` on a probe). Confirm PostSyncer ids
   and `SCHEDULED`, then delete the PostSyncer post so it never goes live.
4. Script Studio no longer talks to PostSyncer. Local video/post work uploads
   to CM (`content_machine.py`). Schedule only through CM, dashboard or
   `POST /api/v1/posts|videos/{human_id}/publish`.

The Laravel scheduler already runs on Railway `cm-worker` (`php artisan
schedule:work`). `postsyncer:sync-scheduled` every five minutes live-checks
PostSyncer and marks a scheduled post or video `posted` once every group is
`PUBLISHED`. Do not add a separate cron service.

Markdown `**PostSyncer:**` lines in personal-content post files remain an
archive after cutover; CM records are the source of truth.
