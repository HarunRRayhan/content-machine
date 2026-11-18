# SocialReins publishing

Every post and video publishes through SocialReins: publish, schedule, retry, reschedule, take
down and status. Content Machine no longer talks to PostSyncer (removed 2026-11). Configure it in
**Settings > SocialReins**.

| Field | Purpose |
|---|---|
| **API base URL** | Default `https://brain.socialreins.com/v1`. Public HTTPS only (no userinfo, query or fragment, no private or loopback address). Every call re-checks DNS and pins the checked addresses, and redirects are never followed, so the tokens go only to that host. Changing it clears the saved tokens and webhook secret. |
| **Default language** | The language that must have a set and token before anything publishes. |
| **Set id + token, per language** | One SocialReins set per language (English, Bangla) and its set-limited API token (`sr_set_live_...`). Encrypted, write-only. |
| **Platforms this set covers, per language** | Only ticked platforms are published for that language. A language with no saved list covers nothing. |
| **TikTok visibility** | One of `SELF_ONLY`, `FOLLOWER_OF_CREATOR`, `MUTUAL_FOLLOW_FRIENDS`, `PUBLIC_TO_EVERYONE`. Required for TikTok: with none chosen a TikTok publish is refused, never defaulted to public. |
| **Webhook secret** | The `whsec_...` secret of the SocialReins team webhook. |

Set the SocialReins team webhook URL to `https://<cm host>/socialreins/webhook/<workspace id>`
(Settings > SocialReins shows it). Without a webhook, `socialreins:reconcile` (every five
minutes) reads `GET /posts?group_id=` and reaches the same states.

The post-type matrix (which platforms are on, off or ask per post type) is read from the matrix
saved under `settings.postsyncer.post_types` before PostSyncer was removed, or the Script Studio
defaults when there is none. There is no editor for it any more.

## Coverage

- A (language, platform) pair the language's set does not cover (TikTok, Bangla X) is left out and
  published nowhere. The item keeps the pairs as `publish_progress.left_out` (`language:platform`)
  and its page says "Left out, not posted anywhere".
- An item where every pair is left out is refused with "Nothing to publish: SocialReins does not
  cover ...".

## Telegram publish requests

A Telegram publish goes through the same path. The SocialReins run keeps the request id in its
stored options and closes the request when it settles: published once the post is handed over
(scheduled counts, so `/cancel` no longer applies), failed when the run fails. A retry reopens a
failed request. A late webhook or reconcile never closes a request that an edit moved back to
awaiting approval. A dashboard retry names no request: it adopts the post's one failed request when
the bot is still connected, and otherwise publishes without it.

## PostSyncer history

Old posts and videos may still carry PostSyncer data (`postsyncer.groups`, a `publish_progress`
without a `driver`). It stays in the database and is shown read-only on the calendar, the dashboard
and the post or video page. Such an item is history: publish, reschedule and take down refuse it
with "This was published through PostSyncer, which Content Machine no longer uses." Change it on
the platforms directly. A PostSyncer run that provably created nothing (no completed group and no
uncertain create) does not count and publishes through SocialReins like a new item.

## How a publish maps

`PostPublishPlanner` and `VideoPublishPlanner` plan the publish. Their `PublishGroup`s of one
language become **one** `POST /v1/post-groups`: the language's set, the union of the
groups' platforms, and one override per platform with its caption, media, thread parts,
first comment, cover and TikTok settings. CM `twitter` is brain `x`.

- LinkedIn gets the carousel PDF the planner picked; the others get images.
- A thread is the caption plus parts; each part takes its slice of the images as before.
- A video is the first media item, the cover goes only to Instagram, Facebook and YouTube.
- Drive links are passed as the planner resolves them. That is the same
  `drive.usercontent.google.com/download?...&confirm=t` URL brain builds from a share link.
- Publish now sends `scheduled_at` = now (brain treats a past time as now and its tick
  publishes within a minute). There is no atomic "publish now" for a group.

## Idempotency

Every write carries an `Idempotency-Key`: `cm:ws<workspace>:<post|video>:<id>:<language>:<sequence>`.
The JSON body is frozen in `socialreins_groups.request` before the first call.

- No answer (timeout, 5xx, 429): the row stays `pending`. The next run asks `GET /idempotency/{key}`
  what the key made: a stored 201 becomes the group (nothing is sent again), a stored 4xx closes
  the row, "still processing" waits. Only when brain has nothing under the key (404) is the frozen
  body resent, byte for byte under the same key, even days later.
- Refused (4xx): brain stored the refusal and nothing was created. The row is `rejected` and
  the next attempt is sequence + 1, a new key. `422 tiktok_settings_required` is reported as a
  workspace settings problem (choose a TikTok visibility).
- 422 `idempotency_key_reused` is resolved with the same lookup; the key is never changed
  automatically.
- Failed targets: a retry never creates a new group. It calls `POST /post-groups/{id}/retry` on the
  same group (key `cm:ws<ws>:retry:<group id>:<attempt>`, a new attempt number after every answer).
  Brain re-queues only failed targets that are not ambiguous and continues half-sent threads after
  the parts already out. Targets it lists as `outcome_unknown` (409, or `skipped_targets`) are
  shown as "Check the platform" and are not retried again: look at the account first.

Take down (`POST /api/v1/posts|videos/{human_id}/take-down`) calls `DELETE /posts/{id}` per
SocialReins post with its own key, and moves to the next key after a refusal so a retry
really tries again. It never reports success while something may be live: a request that got
no answer that `GET /idempotency/{key}` cannot resolve ("status unknown"; once it resolves the
group is taken down like any other), a post still publishing and a partly
published failed post (brain cannot delete a failed post) are reported; a post that failed with
nothing live is skipped. Brain cannot delete on TikTok; that platform is reported as a problem.

Reschedule (`POST /api/v1/posts|videos/{human_id}/reschedule`) first reads every SocialReins post
and refuses, changing nothing, unless each is still `scheduled`. Then each SocialReins post moves
with `PUT /posts/{id}` and the new `scheduled_at`, recorded on its stored result. Retrying with the
same time finishes a partial run, since a post already at that time is skipped. Only a post or
video published through SocialReins can be rescheduled.

## Status

`post.target.published|failed|deleted` webhooks (HMAC-SHA256 over `<t>.<raw body>`,
`SocialReins-Signature: t=..,v1=..`, five minute window) update the per-post results; the
post or video becomes `posted` when every platform published, stays `scheduled` while any is
in flight and is `failed` (naming the platforms) once nothing is in flight and one failed.
An event names one target only, so after each one CM reads `GET /post-groups/{id}` (all accounts,
the post's own status, `expected_targets`); a group counts as settled only when that many targets
have reported, and reconcile keeps checking groups until it does. Results are merged under a row lock
and never move a target backwards, so an older snapshot cannot overwrite a newer webhook.
An event for a group CM does not know answers 404 so brain retries it.
The API returns these results as `socialreins` on a post or video ([api.md](api.md)).

There are no manual reconcile commands: the key replay covers them. Videos have no Telegram
publish requests.
