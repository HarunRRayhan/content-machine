# Video Series Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make video series explicit and navigable, starting with the five VPN videos.

**Architecture:** Store one workspace-scoped series and per-video part numbers, expose a guarded API for atomic ordering, and render series navigation in the existing Inertia video studio.

**Tech Stack:** Laravel 13, PostgreSQL, Inertia 3, React 19, TypeScript, Pest.

**Spec:** `docs/superpowers/specs/2026-09-28-video-series-design.md`

## Global Constraints

- Keep series data scoped to the current workspace.
- Do not change video lifecycle, script, deck, captions, or publishing state.
- Preserve explicit unique part order and allow later parts.

## Review Focus

- Cross-workspace video IDs must be rejected.
- Duplicate IDs or part numbers must be rejected.
- Reordering must be atomic and not leave mixed old/new positions.
- A video in another series must not be silently moved.
- Series pages must not leak another workspace's titles.

---

### Task 1: Persistence and ordering

**Files:** migration, `VideoSeries` model, `Video` relation, `SaveVideoSeriesAction`, DTO, action test.

- [x] Add tests for ordered assignment, replacement, duplicates, and workspace isolation.
- [x] Add series table and nullable series membership/part fields with unique constraints.
- [x] Implement atomic, idempotent save action.
- [x] Run focused tests and formatter.

### Task 2: API and pages

**Files:** API controller/routes/resource, web controller/routes, Inertia pages, video show page, feature tests.

- [x] Add API and page tests for authorization, ordering, and navigation.
- [x] Add guarded series API and read-only workspace pages.
- [x] Show Series menu on each video page and ordered part list on series page.
- [x] Run focused tests, type check, lint, formatter, static analysis.

### Task 3: Delivery and VPN assignment

- [x] Self-review diff and run required checks.
- [ ] Open PR, wait for CI, merge when allowed by repository rules.
- [ ] Through the guarded Content Machine API, create VPN and assign V-85 through V-89 as parts 1 through 5.
- [ ] Verify live video and series pages and API order.
