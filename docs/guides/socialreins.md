# SocialReins publish driver

A workspace publishes through PostSyncer (the default) or through SocialReins. The
driver is chosen per workspace in **Settings > SocialReins**; the PostSyncer settings,
jobs and tests are not touched by it. Changing the driver or its routed languages is
blocked while a publish is queued or running.

## Language routing

**Languages through SocialReins** limits the driver to some languages, for a staged run
(tick only Bangla to leave everything with English on PostSyncer). Nothing ticked means
every language, as before.

- A post or video goes through SocialReins only if the driver is SocialReins and every
  language it carries is ticked. Otherwise it goes through PostSyncer whole. One subject is
  never split across the two.
- A post carries every language it has captions for, as `PostPublishPlanner` reads them. A
  video carries its one `language`. Publish options (`platforms`) do not change the answer,
  so publishing a mixed post to one platform still goes through PostSyncer.
- A language that is only the planners' fallback (an empty or `both` language with untagged
  captions) is not known, so that subject goes through PostSyncer.
- With languages ticked, a Telegram publish request stays on PostSyncer, which is what closes the request, unless the post is already on SocialReins.
- A subject already on a driver stays there for retry, take down, reconcile and status,
  whatever the settings say later. SocialReins: `publish_progress.driver`, or
  `socialreins_groups` with a pending or live group. PostSyncer: `postsyncer.groups` with a
  post id, or a `publish_progress` without a driver. A subject fully taken down from
  SocialReins follows the settings again.
- With languages ticked, one ticked language with a set and token makes SocialReins ready,
  so the default language may have no set. A subject in a ticked language with no set fails
  with a clear error.

| Field | Purpose |
|---|---|
| **Publish driver** | `postsyncer` (default) or `socialreins`. Stored as `settings.publish_driver`. |
| **API base URL** | Default `https://brain.socialreins.com/v1`. Public HTTPS only (no userinfo, query or fragment, no private or loopback address). Every call re-checks DNS and pins the checked addresses, and redirects are never followed, so the tokens go only to that host. Changing it clears the saved tokens and webhook secret. |
| **Languages through SocialReins** | Stored as `settings.socialreins.routed_languages`. See [Language routing](#language-routing). |
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

Not supported through this driver: PostSyncer-style reconcile commands (not needed, the key
replay covers them) and Telegram publish requests (the request keeps its approved state).
