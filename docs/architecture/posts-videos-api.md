# Posts + Videos API (full content in CM)

**Status:** implementing  
**Decision:** 1C + 2B — full video/post records including decks live in Content Machine; personal-content Tailscale app keeps only HyperFrames edit + presentation player, everything else reads CM over the API.

## Source of truth

Content Machine Postgres is the only source of truth for:

- Ideas (already)
- Scratch pad (already)
- **Videos** — script, captions, language, pipeline status, deck package
- **Posts** — body/captions, platforms, images, pipeline status

personal-content markdown (`scripts/`, `posts/`, `VIDEOS.md`, `POSTS.md`, decks) becomes a frozen archive after import, same pattern as `scratchpad/`.

## Schema additions

### `videos`

| Column | Type | Notes |
|---|---|---|
| `language` | string nullable | `bn` / `en` |
| `slug` | string nullable | URL/file slug |
| `script_markdown` | longText nullable | spoken script body |
| `captions` | jsonb | per-platform caption blocks |
| `deck_manifest` | jsonb | slide list / player cues |
| `status` | string | was enum(`draft`) only — now pipeline string |

**Video statuses:** `draft`, `pending`, `ready`, `recorded`, `scheduled`, `posted`, `archived`, `dropped`

`human_id` stays the API address. New promotions still get `V-N`. Imported personal-content rows keep `BV-N` / `EV-N` so agents and old links don't renumber.

### `posts`

Same shape minus script/deck:

| Column | Type |
|---|---|
| `language` | string nullable |
| `slug` | string nullable |
| `captions` | jsonb |
| `platforms` | jsonb |
| `status` | string pipeline |

**Post statuses:** `draft`, `ready`, `scheduled`, `posted`, `archived`, `dropped`

Imported ids keep `BP-N` / `EP-N` / `P-N`. New promotions stay `P-N`.

### Attachments

`attachments.role` gains `deck` (zip/html package for the recording player). Post images keep `image` / `cover`.

## API

Token abilities (additive): `videos:read`, `videos:write`, `posts:read`, `posts:write`.

| Method | Path | Ability |
|---|---|---|
| GET | `/api/v1/videos` | videos:read |
| GET | `/api/v1/videos/{human_id}` | videos:read |
| POST | `/api/v1/videos` | videos:write |
| PATCH | `/api/v1/videos/{human_id}` | videos:write |
| POST | `/api/v1/videos/{human_id}/deck` | videos:write |
| GET | `/api/v1/videos/{human_id}/deck` | videos:read |
| GET | `/api/v1/videos/{human_id}/media/{mediaAsset}` | videos:read |
| GET/POST/PATCH | `/api/v1/posts` … | posts:* |
| POST | `/api/v1/posts/{human_id}/images` | posts:write |
| POST | `/api/v1/posts/{human_id}/publish` | posts:write; queues the resumable PostSyncer publish job |
| GET | `/api/v1/posts/{human_id}/media/{mediaAsset}` | posts:read |

List endpoints are cursor-paginated like ideas. Create accepts an optional `human_id` for idempotent import.

## Local Tailscale after cutover

- **Keep:** HyperFrames edit/render/cover, presentation player (loads deck from CM)
- **Read-only mirror:** Videos/Posts tabs (optional) via API
- **Remove writes:** local script/post markdown edits, local status flips, local reserve-id as source of truth

## Migration order

1. Schema + API + tests (this slice)
2. One-shot importer from personal-content → CM
3. personal-content client + Script Studio read-only
4. Player switched to CM deck URLs
5. Freeze local markdown as archive
