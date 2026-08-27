# PostSyncer

Content Machine configures PostSyncer per workspace and schedules or publishes
**posts and videos** from the dashboard. PostSyncer still delivers to Facebook,
Instagram, TikTok, YouTube, and the other connected accounts; CM only
orchestrates.

After cutover, Script Studio on Tailscale is read-only for content and no longer
owns publish. Set `CONTENT_MACHINE_PUBLISH=1` on the Script Studio server to
disable its local PostSyncer endpoints (see personal-content
`docs/postsyncer-setup.md`).

## Settings → PostSyncer

Workspace owners configure PostSyncer at **Settings → PostSyncer**
(`/settings/postsyncer`). Setup is a gated process: Connecting, then
Bangla workspace, then English workspace. The old
`/dashboard/settings/postsyncer` URL redirects there.

| Field | Purpose |
|---|---|
| **API key** | Write-only after save. Rotated by pasting a new key. Encrypted at rest. |
| **API base** | Default `https://postsyncer.com/api/v1` |
| **Upload base** | Default `https://upload.postsyncer.com/api/v1` (reserved for optional file upload fallback; v1 uses link upload on the API base) |
| **Publish enabled** | Cutover flag. When off, Schedule/Publish buttons stay hidden even if the API key is set. Turn on only after settings validate and you are ready to move off Script Studio. |
| **Bangla / English workspace id** | PostSyncer workspace for each language leg |
| **Per-platform account id + handle** | One row per platform in each language block |
| **Post-type matrix** | Same rules as personal-content `web/post_types.json`: on / off / ask / unsupported per platform × format |
| **Refresh accounts** | Calls PostSyncer `GET /accounts?workspace_id=…` for the selected language workspace and shows a diff before apply |

Schedule and Publish stay disabled until the API key is set, both language
workspace ids are filled, and **Publish enabled** is on.

## PostSyncer API hosts (confirmed)

All authenticated calls use `Authorization: Bearer <api_key>`.

| Operation | Host + path |
|---|---|
| List accounts | `GET https://postsyncer.com/api/v1/accounts` (filter client-side by `workspace_id`) |
| Link-upload media | `POST https://postsyncer.com/api/v1/media/upload/url` with `{ "workspace_id", "urls": ["…"] }` |
| Create / schedule post | `POST https://postsyncer.com/api/v1/posts` |
| File upload (legacy / fallback) | `POST https://upload.postsyncer.com/api/v1/media/upload/file` on the **upload** subdomain, not the main API host |

CM registers Google Drive or attachment URLs through the link-upload endpoint,
then passes returned media ids into `POST /posts`.

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

- **Video Drive URL** (`video_drive_url`) — required. A share link PostSyncer can
  fetch (link-upload).
- **Cover Drive URL** (`cover_drive_url`) — optional. Used as
  `content[0].cover_image` for YouTube / Instagram / Facebook.

### Posts

- Prefer **attachments** uploaded in CM (public/signed URLs sent to link-upload).
- Otherwise **Image Drive URLs** (`image_drive_urls`, one per line) for carousel
  or photo posts without attachments.

Text-only posts schedule when the post-type matrix allows it for the selected
platforms.

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
3. Schedule one draft **post** (attachments or Drive URLs) and confirm PostSyncer
   ids on the show page.
4. Schedule one **video** with Video + Cover Drive URLs.
5. Set `CONTENT_MACHINE_PUBLISH=1` on the Script Studio server and restart it.
   Local `/api/posts/publish` and `/api/publish` return **410**; the UI links to
   [cm.harun.dev](https://cm.harun.dev).

Markdown `**PostSyncer:**` lines in personal-content post files remain an
archive after cutover; CM records are the source of truth.
