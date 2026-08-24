# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
once it reaches 1.0.

## [Unreleased]

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
