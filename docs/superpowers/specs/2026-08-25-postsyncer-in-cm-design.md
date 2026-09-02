# PostSyncer in Content Machine

**Date:** 2026-08-25  
**Status:** approved (2026-08-25)  
**Repo:** content-machine  
**Related:** `docs/architecture/posts-videos-api.md`, personal-content `docs/postsyncer-setup.md`, `web/publish_postsyncer.py`

## Goal

Make Content Machine the place that configures PostSyncer and schedules/publishes **posts and videos**. The Tailscale Script Studio app stops owning publish after cutover. Pipeline status updates live on CM records.

## Decisions (locked)

| Topic | Choice |
|---|---|
| v1 surface | Dashboard settings + Schedule / Publish now + workspace-token publish API |
| Config home | Per CM workspace Settings UI (API key, bases, Bangla/English maps, post-type rules) |
| Scope | Posts **and** videos |
| Video media | Google Drive URLs on the video (`video_drive_url`, `cover_drive_url`); PostSyncer **link upload** |
| Post media | Prefer CM attachments; else `image_drive_urls[]` (hybrid) |
| Bilingual | Full parity: auto-split by language / media set / call shape; store all PostSyncer ids |
| Execution | Queue jobs + dashboard progress (not sync HTTP in the web request) |
| List UI | Studio-like status tabs including Ideation |

## Non-goals (original v1 scope)

- Direct PostSyncer access from agents or Script Studio
- Multipart file upload from CM as the primary path (link upload first; keep upload base URL for optional fallback later)
- Polling PostSyncer to auto-flip `scheduled` → `posted` when a scheduled item goes live
- Pixel-perfect copy of Script Studio CSS
- Removing the separate Ideas nav page (Ideation tab embeds open ideas; Ideas page can remain)

## Follow-up implementation (2026-09-01)

The Google Drive OAuth connection and folder picker originally excluded from v1
are now shipped. Workspace owners can connect Drive from Settings, choose the
video export folder, browse files, and grant an Anyone-with-the-link reader
permission before saving a media URL. See `docs/guides/postsyncer.md` for the
current flow and deployment configuration.

## Architecture

```text
Dashboard Schedule/Publish or workspace-token API
        │
        ▼
Validate settings + media + platforms
        │
        ▼
Enqueue PublishPostJob / PublishVideoJob
        │
        ▼
Queue worker
        │
        ├─ Resolve groups (language workspace, platforms, media, captions)
        ├─ PostSyncer link-upload each media URL
        ├─ Checkpoint private progress before each POST /posts
        ├─ POST /posts per unfinished group
        └─ Write public postsyncer.groups + status + publish_state only after the plan succeeds
```

PostSyncer remains the system that actually posts to Facebook / Instagram / etc. CM only orchestrates.

## Data model

### Workspace `settings.postsyncer`

Stored on `workspaces.settings` (JSON). Secrets encrypted at rest (Laravel encrypted cast or dedicated encrypted column — implementation detail in the plan).

```text
postsyncer:
  api_key            # secret
  api_base           # default https://postsyncer.com/api/v1
                     # (confirm live host against current personal-content client at impl time)
  upload_base        # default https://upload.postsyncer.com/api/v1
  languages:
    bangla:
      workspace_id: string
      platforms:
        facebook: { account_id, handle }
        instagram: { ... }
        # ... same platforms as personal-content workspaces.json
    english:
      workspace_id: string
      platforms: { ... }
  post_types:        # migrate personal-content web/post_types.json
    # platform × type → on | off | ask | unsupported
```

### Videos (new columns)

| Column | Type | Notes |
|---|---|---|
| `video_drive_url` | string nullable | Required before schedule/publish |
| `cover_drive_url` | string nullable | Optional |
| `postsyncer` | jsonb nullable | See shape below |
| `publish_progress` | jsonb nullable | Private resumable operation checkpoint; not part of the API resource |
| `publish_state` | string | `idle` \| `queued` \| `running` \| `succeeded` \| `failed` |
| `publish_error` | text nullable | Last failure message |

### Posts (new columns)

| Column | Type | Notes |
|---|---|---|
| `image_drive_urls` | jsonb nullable | Array of Drive URLs when attachments absent |
| `postsyncer` | jsonb nullable | Same shape as videos |
| `publish_progress` | jsonb nullable | Private resumable operation checkpoint; not part of the API resource |
| `publish_state` | string | same enum |
| `publish_error` | text nullable | |

### `postsyncer` JSON shape

```json
{
  "groups": [
    {
      "post_id": "132129",
      "status": "SCHEDULED",
      "scheduled_at": "2026-08-26 09:12",
      "platforms": ["facebook", "instagram"],
      "language": "bangla"
    }
  ],
  "job_id": "optional-queue-id"
}
```

### Status transitions on success

- Schedule (future `when`) → content `status = scheduled`
- Publish now → content `status = posted`
- Failure → pipeline `status` unchanged; `publish_state = failed`

The public success path remains **all-or-nothing per operation**: if any group
fails, do not set `scheduled`/`posted` and do not write partial ids to public
`postsyncer.groups`. The private `publish_progress` checkpoint records the
normalized options, plan hash, completed group ids, and the current group before
its external create. A normal retry skips completed groups and preserves the
original options. If the response from `POST /posts` may have been lost, the
operation is marked `uncertain` and must be reconciled before any retry.

## Settings UI

Route under workspace settings, e.g. `/dashboard/settings/postsyncer` (exact path follows existing settings layout).

**Capabilities**

- Save API key (write-only after save; show configured / rotate)
- Edit API base + upload base (defaults filled)
- Edit Bangla / English `workspace_id`
- Edit per-platform account id + handle
- **Refresh accounts**: call PostSyncer `GET /accounts` for a language workspace, show diff, apply
- Edit post-type matrix (on / off / ask / unsupported)

**Authorization:** workspace owner/admin only.

**Guards for publish:** missing API key, missing language `workspace_id`, or platform with null `account_id` → Schedule/Publish disabled or platform chip unavailable.

**Seed:** artisan command (or one-off script) to import personal-content `workspaces.json`, `post_types.json`, and API key into the production CM workspace. Not required for greenfield installs.

## Publish / schedule flow

### Entry

Post and video **show** pages: **Schedule** and **Publish now**. Hidden or disabled when `postsyncer.publish_enabled` is false (cutover flag) or settings invalid.

### Dialog

- When: datetime in workspace timezone (default Asia/Dhaka) or Now
- Platforms: chips from content ∩ settings ∩ post-type rules
- `ask` platforms: require explicit confirm checkbox
- Summary of language groups and media sources (attachment vs Drive)
- Video: show/edit Drive URLs if missing
- Post: show attachment count or Drive URL list

### Preflight

1. Load workspace PostSyncer config  
2. Resolve media  
   - Video: Drive URLs  
   - Post: attachment public/signed URLs if present, else `image_drive_urls`; text-only when type allows  
3. Build groups using ported rules from personal-content `publish_post` / related helpers (language workspace, Twitter thread isolation, first-comment / Threads non-join, media-set splits)  
4. Reject if any selected platform unsupported or unconfigured  

### Job

1. Set `publish_state = queued` (then `running` when worker starts)  
2. Persist the operation options and plan metadata in private `publish_progress`
3. For each unfinished group: link-upload media, checkpoint the current group, then create the PostSyncer post
4. Checkpoint each returned PostSyncer id; do not expose it as public success yet
5. On full success: write `postsyncer.groups`, set pipeline status, `publish_state = succeeded`
6. On failure: `publish_state = failed`, `publish_error = …`; preserve the checkpoint

UI: banner on detail + list badge while queued/running; error + Retry after
failure when the checkpoint has no unknown external create. An `uncertain`
checkpoint is a manual reconciliation state, not an automatic retry. An
operator verifies the remote post or video and runs
`php artisan postsyncer:reconcile-post WORKSPACE_ID HUMAN_ID POSTSYNCER_ID`
or `php artisan postsyncer:reconcile-video WORKSPACE_ID HUMAN_ID POSTSYNCER_ID`.
The command checkpoints the verified group before the normal Retry continues.

Post publishes use the dedicated `postsyncer` database connection and queue on
`cm-web`. The queue name is separate from the default `scratchpad` queue because
the database driver does not store a connection name on each job row. The
worker timeout is 900 seconds and the dedicated queue visibility window is 960
seconds.

### Link upload

Use PostSyncer’s URL/link media registration (not Tailscale multipart). Exact endpoint/payload verified against current PostSyncer docs at implementation time; personal-content today only implements file upload — CM adds the link path as new client code in Laravel.

## List UI (status tabs)

### Posts

Tabs + counts: **Ideation** | **Draft** | **Ready** | **Scheduled** | **Posted** | **Archived** | **Dropped**

- Ideation → open ideas with `kind = post`
- Other tabs → posts with matching `status`
- Default tab: Draft

### Videos

Tabs + counts: **Ideation** | **Draft** | **Pending** | **Ready** | **Recorded** | **Scheduled** | **Posted** (label **Published**) | **Archived** | **Dropped**

- Ideation → open ideas with `kind = video`
- Default tab: Pending

Secondary filters: search, language. Table layout: id, title, platforms/score, status pill, open.

## Local Tailscale cutover

After one successful CM publish in production:

1. Disable Script Studio publish endpoints and PostSyncer publish UI  
2. Optional deep link to CM schedule page  
3. Keep recording / HyperFrames / presentation player  
4. Docs: PostSyncer SoT is CM; markdown `**PostSyncer:**` lines are archive  

Feature flag: workspace `postsyncer.publish_enabled` (or env). When false, CM hides Schedule/Publish so local can remain temporarily.

## Testing

- Unit: group splitting fixtures ported from known posts (e.g. bilingual P-48 patterns), media resolution hybrid  
- Feature: settings save/encrypt, refresh-accounts mock, enqueue job, job success/failure status transitions, resumable publish checkpoints, and unknown-outcome handling
- Fake PostSyncer HTTP client in tests (no live network)  
- Manual: schedule a draft post and a video with Drive links against PostSyncer staging/prod with Harun’s confirmation  

## Implementation order (high level)

1. Migrations + model fields  
2. Settings page + seed command  
3. PostSyncer PHP client (accounts, link media, create post)  
4. Group planner (port rules)  
5. Jobs + detail Schedule UI (posts, then videos)  
6. Status tabs on index pages  
7. Cutover flag + personal-content disable publish  
8. Docs  

Detailed task breakdown belongs in `docs/superpowers/plans/` after this spec is approved.

## Open points (resolve at implement, not blockers)

1. Confirm live PostSyncer API host path (`/api/v1` vs `/api/v1` variants in older notes) against current docs  
2. Exact link-upload request/response schema  
3. Whether attachment URLs must be publicly fetchable by PostSyncer or need a short-lived signed URL CM exposes  
4. Encryption helper already used elsewhere in CM vs new pattern for `api_key`  

## Self-review checklist

- [x] No unresolved placeholders left as “TBD” for product behavior  
- [x] Consistent with locked decisions (B dashboard, A settings, Drive A, media C, bilingual A, queue 2)  
- [x] Scope excludes direct PostSyncer access; Drive OAuth/folder-picker follow-up is shipped and documented in `docs/guides/postsyncer.md`
- [x] Cutover and rollback flag present  
- [x] Ties list-tab UX to the same project without blocking publish  
