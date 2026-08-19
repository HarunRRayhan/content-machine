# 0001: Receive Telegram updates via webhook, not polling

## Status

Accepted

## Context

The pipeline this project replaces captured Telegram messages by running a
`claude` CLI session inside a tmux pane on a laptop, long-polling Telegram's
`getUpdates` API. That worked, but it was tied to the laptop being awake and
online: closing the lid, losing wifi, or the machine sleeping overnight all
silently stopped capture until someone noticed and restarted the session.
There was no server, so there was nothing to receive a push from Telegram in
the first place.

content-machine runs on an always-on server (Railway), so that constraint no
longer applies. Telegram supports two ways to receive updates: long-polling
`getUpdates`, or registering a webhook URL that Telegram calls directly.

## Decision

Use a Telegram webhook, delivered to a public HTTPS endpoint on the
always-on deployment, instead of polling.

The endpoint is authenticated two ways:

- A secret, unguessable path segment (the webhook URL itself is not
  discoverable, unlike a fixed `/telegram/webhook` route).
- The `X-Telegram-Bot-Api-Secret-Token` header, set when the webhook is
  registered with Telegram and checked on every incoming request, so a
  request to the right path without the right header is still rejected.

## Consequences

- Capture no longer depends on any single machine staying awake; as long as
  the server is up, Telegram updates arrive.
- The app needs a real deployed HTTPS endpoint before the bot can receive
  anything, so local development needs a tunnel (e.g. `ngrok`) or a
  polling fallback for testing, which isn't decided yet.
- The bot itself, its webhook route, and the secret-token verification are
  not implemented in this repository yet. This ADR records the decision
  ahead of that work so the shape is settled before the code is written.
