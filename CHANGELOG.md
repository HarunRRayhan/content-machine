# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
once it reaches 1.0.

## [Unreleased]

### Changed
- PostSyncer status sync makes at most 20 requests a run, never re-checks a published group, checks future, failed and long-overdue groups once a day, and stops on a 429 or when the remaining allowance is low, so it no longer starves publishes of PostSyncer's 60 requests a minute.
- A PostSyncer 429 during a publish is waited out and retried on every call (media upload, create, lookups); one that outlasts the 120-second budget is a plain failure that can be retried, no longer an "uncertain" outcome that needs reconciling.
- PostSyncer status sync skips groups whose scheduled time has not come yet, so it no longer exhausts PostSyncer's rate limit (429) with a call per scheduled group every five minutes.
- SocialReins publishing asks for public YouTube uploads, matching PostSyncer; SocialReins now uploads private unless told otherwise.

### Added
- Workspace API tokens: hashed bearer tokens minted/revoked from the Team
  page, with per-token abilities (scratchpad:read/write, ideas:read/write).
- JSON API under `/api/v1` for the Scratch Pad and Ideas: list/show/capture
  (text, link, photo, voice)/PATCH/delete/triage/media, plus ideas
  list/show/PATCH addressed by human_id. Captures record `source: api`;
  history rows are attributed to the token by name.
- `UpdateScratchpadEntryAction`: the first edit path for an entry's
  title/body/language, recording content_versions per changed field;
  dropped entries refuse edits.
- Guide: [docs/guides/api.md](docs/guides/api.md).
