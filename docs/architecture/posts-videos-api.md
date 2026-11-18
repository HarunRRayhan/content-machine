# Posts and videos

Content Machine stores posts and videos in PostgreSQL and exposes them through
the dashboard, workspace-token API, and MCP tools. Local content tools use the
API to read and update records. `human_id` is the stable public identifier.
Imported legacy IDs such as `BP-24`, `BV-60`, and `EV-11` remain valid.

## Records and media

Posts store their body, language, status, platform list, structured captions,
Drive image URLs, publish metadata, and attachments. Videos store their script,
language, status, structured captions, Drive media URLs, presentation manifest,
and attachments. Both use workspace-owned `MediaAsset` records linked through
`Attachment`.

API list responses are slim by default. Clients can request larger post or video
fields with `include`; show endpoints return the full record. API Resources
define these payloads. See the [API guide](../guides/api.md) for fields, filters,
and endpoint behavior.

## Publishing

Dashboard, API, MCP and Telegram publish requests enter the same enqueue Action
and job. SocialReins settings belong to a workspace. The publish job builds groups
by language, platform, and media set and sends one idempotent SocialReins request
per language.

The [SocialReins guide](../guides/socialreins.md) documents status, retries,
reschedule and take down. Old records may still carry PostSyncer groups; they are
history, shown read-only, and are never published again.

## Remaining API gaps

The API guide's [Not here yet](../guides/api.md#not-here-yet) section is the
current gap list. At this revision it identifies deck-package upload endpoints,
idea promotion over the API, and rate limiting beyond the default throttle. Do
not use the August implementation plans as a current backlog.
