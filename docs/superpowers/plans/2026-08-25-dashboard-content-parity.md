# Dashboard Content Parity: historical implementation plan

> Historical snapshot from 2026-08-25. Its unchecked tasks were not maintained as work shipped, so they do not describe current completion or remaining work. The dashboard has since been implemented in the codebase at baseline `fafde1e` (2026-09-24). Check current controllers and pages before reviving any task.
>
> Current references: [architecture overview](../../architecture/overview.md), [posts and videos](../../architecture/posts-videos-api.md), [API guide](../../guides/api.md), and [PostSyncer guide](../../guides/postsyncer.md). The API guide lists the verified remaining API gaps. This plan does not claim those gaps are dashboard parity work.

**Goal:** Content Machine dashboard videos/posts match Script Studio feature depth (script, captions, presentation, filters), backed by the database.

**Architecture:** Controllers present full DB fields (normalized captions). React show pages use Studio-like tabs. Decks/images land in storage + `deck_manifest` / attachments. Presentation plays via a dedicated fullscreen route that boots the same Reveal deck sources.

**Tech Stack:** Laravel Inertia React, existing Tabs UI, Reveal.js CDN for decks

**Spec:** Chat-approved design 2026-08-25, full Studio feature parity for videos + posts

## Global Constraints

- No em dashes in user-facing copy
- Workspace scoping on every query
- Keep API VideoResource fields as source of truth shapes
- Local personal-content markdown remains archive only

---

## Task 1: Video controller + show/index UI

- [ ] presentDetail includes script_markdown, captions (normalized), deck_manifest, language, slug, number
- [ ] Index filters: status, language, q
- [ ] Show tabs: Overview, Script, Captions, Presentation (if deck)

## Task 2: Post controller + show/index UI

- [ ] Same for body, captions, platforms, language, images from attachments
- [ ] Index filters: status, language, q
- [ ] Show tabs: Overview, Captions, Images

## Task 3: Import media

- [ ] Script to upload post images + deck packages into CM
- [ ] Fill deck_manifest with engine/steps/css/js refs

## Task 4: Presentation player

- [ ] Fullscreen route + page booting Reveal from stored deck
- [ ] Wire Presentation tab button to open it

## Task 5: Verify

- [ ] Browser check BV-60 / P-48 show rich content
- [ ] Tests for presentCaptions normalization + filter queries
