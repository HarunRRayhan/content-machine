# Video series

Content Machine needs a workspace-scoped series whose videos have explicit part numbers. A series has a stable slug and title. A video belongs to at most one series, and a part number is unique within that series. The VPN series initially contains V-85 through V-89 as parts 1 through 5. More parts can be appended later.

The video page shows a Series menu beneath its heading. The menu links to all series, the current series, and its ordered parts. The series index lists the workspace's series. A series detail page lists its videos in part order and links to each video. Every read is scoped to the current workspace.

The workspace-token API provides an idempotent `PUT /api/v1/series/{slug}` accepting `title` and an ordered `videos` array of video human IDs. The operation validates that every video belongs to the same workspace, rejects duplicates or videos already assigned to another series, and atomically replaces the series order. This provides a maintained path for adding later parts and for the initial VPN assignment without hard-coding production IDs in a migration. It does not alter video status, script, captions, deck, or publish state.

The existing video API includes series slug, title, and part when present. Series pages remain useful when some videos are Ready and others Pending. The UI uses the app's existing typography and navigation rather than presentation artwork.
