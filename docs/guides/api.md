# JSON API (v1)

External clients (personal-content / Script Studio, an MCP server) talk to
content-machine through a small token-authenticated JSON API under `/api/v1`.
The first slice covers the Scratch Pad and Ideas: capture anywhere, pull
entries, work on them, push updates back, triage them into ideas.

## Authentication

1. Log in at [cm.harun.dev](https://cm.harun.dev) and open **Team**.
2. Open **API access**, name the client, tick its abilities, and click
   **Create token**.
3. Copy the plaintext from the panel that appears (it stays visible until
   you click "I've saved it" or leave the page). It is shown exactly once;
   only a SHA-256 hash is stored, so losing it means minting a new one.
4. Send it on every call:

```
Authorization: Bearer <your-token>
```

A token is bound to exactly one workspace — there is no workspace header,
by design. Revoking it in the dashboard takes effect immediately (the next
request reads as `401`). Abilities:

| Ability | Grants |
|---|---|
| `scratchpad:read` | list/show entries, stream their media |
| `scratchpad:write` | capture text/link/photo/voice, edit, delete, triage |
| `ideas:read` | list/show ideas |
| `ideas:write` | edit an idea's title/score/trend/rationale/body |
| `videos:read` | list/show videos |
| `videos:write` | create/update videos (incl. import with explicit human_id), queue a PostSyncer publish |
| `posts:read` | list/show posts |
| `posts:write` | create/update posts, upload post images, queue a PostSyncer publish |

Missing ability → `403`. Bad or revoked token → `401`.

## Endpoints

Entries are addressed by `public_id` (a ULID returned by every response);
ideas by `human_id` (`PI-7`, `VI-3`).

| Method | Path | Ability | Notes |
|---|---|---|---|
| GET | `/api/v1/scratchpad?status=new&kind=text` | read | defaults to `status=new`; use `all` for everything; cursor-paginated (`data`, `meta.next_cursor`) |
| POST | `/api/v1/scratchpad/text` | write | body: `{ "body": "..." }` |
| POST | `/api/v1/scratchpad/link` | write | body: `{ "url": "..." }`; resolution runs queued |
| POST | `/api/v1/scratchpad/photo` | write | multipart `photo` (+ optional `caption`) |
| POST | `/api/v1/scratchpad/voice` | write | multipart `audio` (+ optional `language`: `bn`/`en`); transcription queued |
| GET | `/api/v1/scratchpad/{public_id}` | read | full detail incl. transcription/link/idea |
| PATCH | `/api/v1/scratchpad/{public_id}` | write | any of `title`/`body`/`language`; each change is versioned; dropped entries refuse edits (409) |
| DELETE | `/api/v1/scratchpad/{public_id}` | write | refuses entries already triaged into an idea (409) |
| POST | `/api/v1/scratchpad/{public_id}/triage` | write | same payload as the dashboard: `target` = `post_idea`/`video_idea`/`drop` (+ `title`, `score`, `trend`, `rationale`, `drop_reason`) |
| GET | `/api/v1/scratchpad/{public_id}/media/{id}` | read | streams a private photo/audio file |
| GET | `/api/v1/ideas?kind=post&status=open` | read | cursor-paginated |
| GET | `/api/v1/ideas/{human_id}` | read | |
| POST | `/api/v1/ideas` | write | create; pass `human_id` for idempotent import (bumps `id_sequences`) |
| PATCH | `/api/v1/ideas/{human_id}` | write | `title` required, plus `score`/`trend`/`rationale`/`body` |
| GET | `/api/v1/videos` | videos:read | filters: `status`, `language`. Default list is slim (no `script_markdown` / `captions` / `deck_manifest`); pass `include=full` or `include=script_markdown,captions,deck_manifest` to opt in. Always includes `has_script` / `has_captions` / `has_deck`. |
| GET | `/api/v1/videos/{human_id}` | videos:read | `V-12` or imported `BV-53` (full record, including deck) |
| POST | `/api/v1/videos` | videos:write | create; pass `human_id`+`number` for idempotent import |
| PATCH | `/api/v1/videos/{human_id}` | videos:write | script, captions, status, deck_manifest, Drive URLs, … (publish metadata is read-only). Drive URLs must be public Google Drive file links. |
| POST | `/api/v1/media-urls/check` | any token | probe a Drive URL: `{ url }` → `{ accessible, message, file_id, share_url, fetch_url }` |
| POST | `/api/v1/videos/{human_id}/publish` | videos:write | queue a PostSyncer schedule/publish (`when`, `platforms`, `confirm_ask`). The video needs a Video Drive URL. Always send `when` to schedule. |
| GET | `/api/v1/posts` | posts:read | filters: `status`, `language`. Default list is slim (no `body` / `captions`); pass `include=full` or `include=body,captions` to opt in. Always includes `has_body` / `has_captions` / `approval_state`. |
| GET | `/api/v1/posts/{human_id}` | posts:read | full record |
| POST | `/api/v1/posts` | posts:write | create / idempotent import |
| PATCH | `/api/v1/posts/{human_id}` | posts:write | body, captions, platforms, status, image_drive_urls, … (publish metadata is read-only) |
| POST | `/api/v1/posts/{human_id}/images` | posts:write | multipart `image`; attaches to the post (idempotent on same bytes) |
| POST | `/api/v1/posts/{human_id}/documents` | posts:write | multipart `document` (PDF); LinkedIn carousel document, idempotent on same bytes |
| POST | `/api/v1/posts/{human_id}/publish` | posts:write | queue a PostSyncer schedule/publish (`when`, `platforms`, `confirm_ask`). Always send `when` to schedule; omitting it is live `publish_now`. Returns the post with `publish_state` = `queued`. The Railway `cm-worker` service runs the job. |
| POST | `/api/v1/posts/{human_id}/reconcile` | posts:write | checkpoint a PostSyncer post after an uncertain create response (`postsyncer_id`). The endpoint verifies workspace and payload before allowing a retry. |
| GET | `/api/v1/posts/{human_id}/media/{id}` | posts:read | streams a private post image or document |

Captures made through the API are recorded with `source: api`, and every
status transition / field change they cause is attributed to the token by
name in the history tables — the same audit trail the dashboard leaves.

## Example

```bash
curl -s https://cm.harun.dev/api/v1/scratchpad?status=new \
  -H "Authorization: Bearer $CONTENT_MACHINE_TOKEN"
```

```bash
curl -s -X PATCH https://cm.harun.dev/api/v1/scratchpad/01J8... \
  -H "Authorization: Bearer $CONTENT_MACHINE_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"body": "corrected transcript"}'
```

## Not here yet

- Deck multipart upload endpoints (manifest fields land via PATCH today;
  binary packages are next)
- Promotion of an idea → video/post over the API (dashboard promote stays)
- Publishing and scheduling
- Rate limiting beyond the default per-minute throttle

## MCP

`POST /mcp` is a Streamable HTTP MCP server for the same workspace token.
Send JSON-RPC 2.0 (`initialize`, `tools/list`, `tools/call`, `ping`).
Notifications (no `id`) get HTTP 202.

```
Authorization: Bearer <your-token>
Content-Type: application/json
```

The API access page has copy-paste setup for Claude, Cursor, Codex, ChatGPT,
Open Code, Command Code, and a generic custom client. Tools cover Scratch Pad,
ideas, videos, and posts.
