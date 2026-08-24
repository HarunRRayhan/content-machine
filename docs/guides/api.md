# JSON API (v1)

External clients (personal-content / Script Studio, an MCP server) talk to
content-machine through a small token-authenticated JSON API under `/api/v1`.
The first slice covers the Scratch Pad and Ideas: capture anywhere, pull
entries, work on them, push updates back, triage them into ideas.

## Authentication

1. Log in at [cm.harun.dev](https://cm.harun.dev) and open **Team**.
2. Under **API tokens**, name the client, tick its abilities, **Create
   token**.
3. Copy the plaintext from the toast. It is shown exactly once; only a
   SHA-256 hash is stored, so losing it means minting a new one.
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
| PATCH | `/api/v1/ideas/{human_id}` | write | `title` required, plus `score`/`trend`/`rationale`/`body` |

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

- Post/video draft endpoints, promotion over the API, publishing/scheduling
  (the private pipeline remains the distributor until that ships)
- Rate limiting beyond the default per-minute throttle
- An MCP transport inside Laravel (clients wrap these routes instead)
