# SocialReins publish driver

A workspace publishes through PostSyncer (the default) or through SocialReins. The
driver is chosen per workspace in **Settings > SocialReins**; the PostSyncer settings,
jobs and tests are not touched by it. Switching is blocked while a publish is queued
or running.

| Field | Purpose |
|---|---|
| **Publish driver** | `postsyncer` (default) or `socialreins`. Stored as `settings.publish_driver`. |
| **API base URL** | Default `https://brain.socialreins.com/v1`. Public HTTPS only (no userinfo, query or fragment, no private or loopback address). Every call re-checks DNS and pins the checked addresses, and redirects are never followed, so the tokens go only to that host. Changing it clears the saved tokens and webhook secret. |
| **Set id + token, per language** | One SocialReins set per language (English, Bangla) and its set-limited API token (`sr_set_live_...`). Encrypted, write-only. |
| **TikTok visibility** | One of `SELF_ONLY`, `FOLLOWER_OF_CREATOR`, `MUTUAL_FOLLOW_FRIENDS`, `PUBLIC_TO_EVERYONE`. Required for TikTok: with none chosen a TikTok publish is refused, never defaulted to public. |
| **Webhook secret** | The `whsec_...` secret of the SocialReins team webhook. |

Set the SocialReins team webhook URL to `https://<cm host>/socialreins/webhook/<workspace id>`
(Settings > SocialReins shows it). Without a webhook, `socialreins:reconcile` (every five
minutes) reads `GET /posts?group_id=` and reaches the same states.

The post-type matrix (which platforms are on, off or ask per post type) is still read from the
workspace's PostSyncer post-type settings, or the Script Studio defaults when there are none.

## How a publish maps

`PostPublishPlanner` and `VideoPublishPlanner` are unchanged. Their `PublishGroup`s of one
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

- No answer (timeout, 5xx, 429): the row stays `pending`; a retry, even days later, resends
  the same bytes under the same key and gets the first result back.
- Refused (4xx): brain stored the refusal and nothing was created. The row is `rejected` and
  the next attempt is sequence + 1, a new key.
- Partial failure (one platform failed after the group was created): a retry plans again but
  sends only the failed platforms as sequence + 1.

Take down (`POST /api/v1/posts|videos/{human_id}/take-down`) calls `DELETE /posts/{id}` per
SocialReins post with its own key, and moves to the next key after a refusal so a retry
really tries again. Brain cannot delete on TikTok; that platform is reported as a problem.

## Status

`post.target.published|failed|deleted` webhooks (HMAC-SHA256 over `<t>.<raw body>`,
`SocialReins-Signature: t=..,v1=..`, five minute window) update the per-post results; the
post or video becomes `posted` when every platform published, stays `scheduled` while any is
in flight and is `failed` (naming the platforms) once nothing is in flight and one failed.
An event for a group CM does not know answers 404 so brain retries it.

Not supported through this driver: PostSyncer-style reconcile commands (not needed, the key
replay covers them) and Telegram publish requests (the request keeps its approved state).
