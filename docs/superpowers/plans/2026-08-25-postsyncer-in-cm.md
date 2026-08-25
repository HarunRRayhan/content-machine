# PostSyncer in Content Machine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Configure PostSyncer per workspace in Content Machine and schedule/publish posts and videos from the dashboard via queued jobs (Drive/attachment URL link-upload), with Studio-like status tabs including Ideation.

**Architecture:** Workspace `settings.postsyncer` holds encrypted API key + Bangla/English account maps + post-type rules. A Laravel HTTP client talks to PostSyncer (`POST /media/upload/url`, `POST /posts`, `GET /accounts`). `PublishPostJob` / `PublishVideoJob` run group plans (ported bilingual split rules). Dashboard Schedule/Publish enqueues jobs; index pages use status tabs.

**Tech Stack:** Laravel 12, Inertia React, PostgreSQL, queued jobs, `Illuminate\Support\Facades\Http`, `Crypt` for API key

**Spec:** `docs/superpowers/specs/2026-08-25-postsyncer-in-cm-design.md`

## Global Constraints

- No em dashes in user-facing copy
- Workspace-scope every query (`Workspace::current()`)
- No live PostSyncer calls in tests (`Http::fake`)
- v1: dashboard only (no agent publish API)
- Media: PostSyncer link upload (`POST https://postsyncer.com/api/v1/media/upload/url`); not Tailscale multipart
- Pipeline status flips only on full job success (all-or-nothing per click)
- Follow existing Action + Job patterns (`SummarizeCaptureJob` delegates to an Action)

## File map

| Path | Responsibility |
|---|---|
| `database/migrations/*_add_postsyncer_publish_columns.php` | Post/Video Drive URL + publish columns |
| `app/Support/Postsyncer/PostsyncerConfig.php` | Read/write/encrypt workspace settings |
| `app/Support/Postsyncer/PostsyncerClient.php` | HTTP: accounts, uploadUrl, createPost |
| `app/Support/Postsyncer/PublishGroup.php` | DTO for one PostSyncer call |
| `app/Support/Postsyncer/PostPublishPlanner.php` | Split post into groups |
| `app/Support/Postsyncer/VideoPublishPlanner.php` | Build video group(s) |
| `app/Support/Postsyncer/MediaUrlResolver.php` | Attachment URLs vs Drive URLs |
| `app/Actions/Postsyncer/UpdatePostsyncerSettingsAction.php` | Persist settings |
| `app/Actions/Postsyncer/PublishPostAction.php` | Run post publish (used by job) |
| `app/Actions/Postsyncer/PublishVideoAction.php` | Run video publish |
| `app/Jobs/PublishPostJob.php` / `PublishVideoJob.php` | Queue wrappers |
| `app/Http/Controllers/Settings/PostsyncerSettingsController.php` | Settings UI |
| `app/Http/Controllers/Posts/PublishPostController.php` | Enqueue post publish |
| `app/Http/Controllers/Videos/PublishVideoController.php` | Enqueue video publish |
| `resources/js/pages/settings/postsyncer.tsx` | Settings form |
| `resources/js/pages/posts/index.tsx` / `videos/index.tsx` | Status tabs |
| `resources/js/pages/posts/show.tsx` / `videos/show.tsx` | Schedule UI + Drive fields |
| `app/Console/Commands/SeedPostsyncerSettingsCommand.php` | Import from personal-content JSON |
| personal-content cutover | Disable local publish after flag |

---

### Task 1: Migrations + model fields

**Files:**
- Create: `database/migrations/2026_08_25_120000_add_postsyncer_publish_columns_to_posts_and_videos.php`
- Modify: `app/Models/Post.php`, `app/Models/Video.php`
- Modify: `database/factories/PostFactory.php`, `database/factories/VideoFactory.php`
- Test: `tests/Unit/Models/PostPostsyncerFieldsTest.php`

**Interfaces:**
- Produces: Post/Video fillable + casts for `image_drive_urls` / `video_drive_url` / `cover_drive_url` / `postsyncer` / `publish_state` / `publish_error`
- `publish_state` values: `idle`, `queued`, `running`, `succeeded`, `failed`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Models;

use App\Models\Post;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostPostsyncerFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_persists_postsyncer_publish_fields(): void
    {
        $workspace = Workspace::factory()->create();
        $post = Post::factory()->for($workspace)->create([
            'image_drive_urls' => ['https://drive.google.com/file/d/abc/view'],
            'publish_state' => 'idle',
            'postsyncer' => ['groups' => []],
        ]);

        $post->refresh();

        $this->assertSame(['https://drive.google.com/file/d/abc/view'], $post->image_drive_urls);
        $this->assertSame('idle', $post->publish_state);
        $this->assertSame(['groups' => []], $post->postsyncer);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PostPostsyncerFieldsTest`  
Expected: FAIL (unknown column / mass assignment)

- [ ] **Step 3: Write migration + model updates**

Migration columns:

**posts:** `image_drive_urls` jsonb nullable; `postsyncer` jsonb nullable; `publish_state` string default `idle`; `publish_error` text nullable  

**videos:** `video_drive_url` string nullable; `cover_drive_url` string nullable; `postsyncer` jsonb nullable; `publish_state` string default `idle`; `publish_error` text nullable  

Add fillable + casts (`array` for json fields) on both models. Add `public const PUBLISH_STATES = [...]`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=PostPostsyncerFieldsTest`  
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add database/migrations app/Models/Post.php app/Models/Video.php database/factories tests/Unit/Models/PostPostsyncerFieldsTest.php
git commit -m "feat: add PostSyncer publish columns on posts and videos"
```

---

### Task 2: PostsyncerConfig (settings read/write + encrypt key)

**Files:**
- Create: `app/Support/Postsyncer/PostsyncerConfig.php`
- Test: `tests/Unit/Support/Postsyncer/PostsyncerConfigTest.php`

**Interfaces:**
- Produces:
  - `PostsyncerConfig::fromWorkspace(Workspace $workspace): self`
  - `->apiKey(): ?string` (decrypted)
  - `->apiBase(): string` default `https://postsyncer.com/api/v1`
  - `->uploadBase(): string` default `https://upload.postsyncer.com/api/v1`
  - `->language(string $lang): array` with `workspace_id`, `platforms`
  - `->postTypes(): array`
  - `->publishEnabled(): bool`
  - `->isConfigured(): bool`
  - `PostsyncerConfig::write(Workspace $workspace, array $input): void` merges into `settings['postsyncer']`, encrypts `api_key` via `Crypt::encryptString` when non-empty; omits key when blank to keep existing

Stored shape matches spec `settings.postsyncer`.

- [ ] **Step 1: Write the failing test**

```php
public function test_write_encrypts_api_key_and_read_decrypts(): void
{
    $workspace = Workspace::factory()->create(['settings' => []]);
    PostsyncerConfig::write($workspace, [
        'api_key' => 'secret-key',
        'api_base' => 'https://postsyncer.com/api/v1',
        'publish_enabled' => true,
        'languages' => [
            'bangla' => ['workspace_id' => '15211', 'platforms' => []],
            'english' => ['workspace_id' => '853', 'platforms' => []],
        ],
        'post_types' => [],
    ]);
    $workspace->refresh();
    $raw = $workspace->settings['postsyncer']['api_key'];
    $this->assertNotSame('secret-key', $raw);
    $this->assertSame('secret-key', PostsyncerConfig::fromWorkspace($workspace)->apiKey());
}
```

- [ ] **Step 2: Run test — expect FAIL**

Run: `php artisan test --filter=PostsyncerConfigTest`

- [ ] **Step 3: Implement PostsyncerConfig**

- [ ] **Step 4: Run test — expect PASS**

- [ ] **Step 5: Commit**

```bash
git commit -m "feat: encrypt and load workspace PostSyncer config"
```

---

### Task 3: PostsyncerClient HTTP wrapper

**Files:**
- Create: `app/Support/Postsyncer/PostsyncerClient.php`
- Create: `app/Support/Postsyncer/PostsyncerException.php`
- Test: `tests/Unit/Support/Postsyncer/PostsyncerClientTest.php`

**Interfaces:**
- Consumes: `PostsyncerConfig`
- Produces:
  - `__construct(PostsyncerConfig $config)`
  - `listAccounts(int|string $workspaceId): array`
  - `uploadFromUrls(int|string $workspaceId, array $urls): array` // returns list of media ids
  - `createPost(array $body): array` // returns PostSyncer post payload including `id`, `status`, `scheduled_at`

Defaults (from PostSyncer docs, 2026-08):
- `POST {api_base}/media/upload/url` JSON `{ workspace_id, urls: [...] }` → `{ media: [{ id, ... }], count_stored }`
- `POST {api_base}/posts` create body (port fields from personal-content `build_post_body`)
- `GET {api_base}/accounts?workspace_id=` (confirm query param against live docs while implementing; fake both shapes in tests if needed)
- Auth: `Authorization: Bearer {apiKey}`

- [ ] **Step 1: Write failing tests with Http::fake**

```php
Http::fake([
    'postsyncer.com/api/v1/media/upload/url' => Http::response([
        'media' => [['id' => 915]],
        'count_stored' => 1,
    ], 200),
]);
$ids = $client->uploadFromUrls(15211, ['https://example.com/a.png']);
$this->assertSame([915], $ids);
```

Also test createPost and error → `PostsyncerException`.

- [ ] **Step 2: Run — FAIL**

- [ ] **Step 3: Implement client**

- [ ] **Step 4: Run — PASS**

- [ ] **Step 5: Commit**

```bash
git commit -m "feat: add PostSyncer HTTP client with URL media import"
```

---

### Task 4: MediaUrlResolver

**Files:**
- Create: `app/Support/Postsyncer/MediaUrlResolver.php`
- Test: `tests/Unit/Support/Postsyncer/MediaUrlResolverTest.php`

**Interfaces:**
- Produces:
  - `forPost(Post $post): array` — list of HTTPS URLs  
    Prefer attachment `Storage::disk(...)->url(...)` when attachments exist; else `image_drive_urls`; empty array = text-only allowed later by planner
  - `forVideo(Video $video): array{video: string, cover: ?string}` — requires `video_drive_url` or throws `InvalidArgumentException`

- [ ] **Step 1–5:** TDD as above; commit `feat: resolve post/video media URLs for PostSyncer`

---

### Task 5: PublishGroup DTO + PostPublishPlanner (single-group first)

**Files:**
- Create: `app/Support/Postsyncer/PublishGroup.php`
- Create: `app/Support/Postsyncer/PostPublishPlanner.php`
- Test: `tests/Unit/Support/Postsyncer/PostPublishPlannerTest.php`

**Interfaces:**
- `PublishGroup` readonly props: `string $language`, `int|string $workspaceId`, `array $platforms`, `array $mediaUrls`, `array $captions` (platform → text), `?CarbonImmutable $when`, `bool $publishNow`
- `PostPublishPlanner::plan(Post $post, PostsyncerConfig $config, array $options): array` returns `list<PublishGroup>`
  - `$options`: `when` (nullable datetime string), `platforms` (optional override), `confirm_ask` (bool)

**v1 of this task:** one language only — if post `language` is `en` use english map, else bangla; one group with all selected platforms that are `on` for detected post type (photo if media, else text). Skip `unsupported` / `off`. If any selected platform is `ask` and `confirm_ask` false, throw.

- [ ] **Step 1–5:** TDD; commit `feat: plan single-language PostSyncer publish groups`

---

### Task 6: PostPublishPlanner bilingual + media-set split

**Files:**
- Modify: `app/Support/Postsyncer/PostPublishPlanner.php`
- Test: extend `PostPublishPlannerTest.php`
- Reference: personal-content `web/server.py` `publish_post()` + `web/publish_postsyncer.py` (read-only)

**Behavior to port (minimum for parity):**
- If captions have both Bangla and English platform sections (or `platforms` lists both langs), emit ≥2 groups targeting bangla/english workspace ids
- Split Twitter thread into its own group when captions contain thread segments (`Tweet 2` / equivalent structure already stored in CM captions JSON — inspect real P-48 captions shape in DB/API before coding)
- Do not merge Threads into Twitter’s thread group
- Different image sets per language → separate groups

Add fixture JSON under `tests/Fixtures/postsyncer/p48_captions.json` captured from a real bilingual post.

- [ ] **Step 1–5:** TDD with fixture; commit `feat: bilingual PostSyncer group splitting for posts`

---

### Task 7: VideoPublishPlanner

**Files:**
- Create: `app/Support/Postsyncer/VideoPublishPlanner.php`
- Test: `tests/Unit/Support/Postsyncer/VideoPublishPlannerTest.php`

**Interfaces:**
- `plan(Video $video, PostsyncerConfig $config, array $options): array` → usually one `PublishGroup` with `mediaUrls` = [video, cover?] and reel-capable platforms from settings ∩ options

- [ ] **Step 1–5:** TDD; commit `feat: plan video PostSyncer publish groups`

---

### Task 8: PublishPostAction + PublishPostJob

**Files:**
- Create: `app/Actions/Postsyncer/PublishPostAction.php`
- Create: `app/Jobs/PublishPostJob.php`
- Test: `tests/Unit/Actions/Postsyncer/PublishPostActionTest.php`
- Test: `tests/Unit/Jobs/PublishPostJobTest.php`

**Interfaces:**
- `PublishPostAction::handle(Post $post, array $options): void`
  1. Set `publish_state=running`
  2. Build config + planner groups
  3. For each group: `uploadFromUrls` → `createPost` body (accounts from config platforms, schedule_type schedule|now)
  4. On full success: `postsyncer.groups`, status `scheduled` or `posted`, `publish_state=succeeded`, clear error
  5. On failure: `publish_state=failed`, `publish_error=message`, do not change pipeline status
- `PublishPostJob` constructor `(Post $post, array $options)` → `handle(PublishPostAction $action)`

Use `Http::fake` for success and failure paths.

- [ ] **Step 1–5:** TDD; commit `feat: queueable post publish via PostSyncer`

---

### Task 9: PublishVideoAction + PublishVideoJob

**Files:**
- Create: `app/Actions/Postsyncer/PublishVideoAction.php`
- Create: `app/Jobs/PublishVideoJob.php`
- Test: `tests/Unit/Actions/Postsyncer/PublishVideoActionTest.php`

Mirror Task 8 for videos (require `video_drive_url`).

- [ ] **Step 1–5:** TDD; commit `feat: queueable video publish via PostSyncer`

---

### Task 10: Settings UI + UpdatePostsyncerSettingsAction

**Files:**
- Create: `app/Actions/Postsyncer/UpdatePostsyncerSettingsAction.php`
- Create: `app/Http/Controllers/Settings/PostsyncerSettingsController.php`
- Create: `app/Http/Requests/Settings/UpdatePostsyncerSettingsRequest.php`
- Modify: `routes/dashboard.php` — add:
  - `GET settings/postsyncer` → `edit` name `dashboard.postsyncer.edit`
  - `PUT settings/postsyncer` → `update`
  - `POST settings/postsyncer/refresh-accounts` → `refreshAccounts`
- Modify: `resources/js/layouts/settings/layout.tsx` — nav item PostSyncer
- Create: `resources/js/pages/settings/postsyncer.tsx`
- Test: `tests/Feature/Settings/PostsyncerSettingsControllerTest.php`

**Page behavior:**
- Show configured/not for API key; blank input keeps existing
- Edit bases, publish_enabled, bangla/english workspace ids + platform table
- Post-type matrix as simple selects
- Refresh accounts: calls client `listAccounts`, returns suggested platform map for confirmation (v1: merge account_id/handle by platform name match)

Authorize: team owner/admin (same gate as telegram settings if one exists; otherwise `abort_unless` owner).

- [ ] **Step 1:** Feature test guest redirect + owner can update publish_enabled
- [ ] **Step 2:** Implement backend + React page (match existing settings form patterns from `settings/profile.tsx`)
- [ ] **Step 3:** `php artisan test --filter=PostsyncerSettingsControllerTest`
- [ ] **Step 4:** Commit `feat: workspace PostSyncer settings page`

---

### Task 11: Seed command from personal-content JSON

**Files:**
- Create: `app/Console/Commands/SeedPostsyncerSettingsCommand.php`
- Test: `tests/Feature/Console/SeedPostsyncerSettingsCommandTest.php`

```bash
php artisan postsyncer:seed {workspace_id} --workspaces=/path/to/workspaces.json --post-types=/path/to/post_types.json --api-key=...
```

- [ ] **Step 1–5:** TDD with temp JSON files; commit `feat: artisan postsyncer:seed for workspace import`

---

### Task 12: Enqueue endpoints + Schedule UI on show pages

**Files:**
- Create: `app/Http/Controllers/Posts/PublishPostController.php`
- Create: `app/Http/Controllers/Videos/PublishVideoController.php`
- Modify: `routes/dashboard.php`
  - `POST posts/{post}/publish` name `dashboard.posts.publish`
  - `POST videos/{video}/publish` name `dashboard.videos.publish`
- Modify: `app/Http/Controllers/Posts/PostsController.php` `presentDetail` — include drive urls, publish_state, publish_error, postsyncer, `postsyncer_ready` bool
- Modify: `app/Http/Controllers/Videos/VideosController.php` similarly
- Modify: `resources/js/pages/posts/show.tsx`, `videos/show.tsx` — Drive URL fields on update form; Schedule / Publish now dialog posting to publish routes
- Test: `tests/Feature/Posts/PublishPostControllerTest.php` — asserts `PublishPostJob` dispatched (`Queue::fake`)

Request body: `{ when: null|string, platforms?: string[], confirm_ask?: bool }`  
Controller sets `publish_state=queued`, dispatches job, redirects back with flash.

Disable buttons when `!postsyncer_ready` or `publish_state` in `queued|running`.

- [ ] **Step 1–5:** TDD + UI; commit `feat: schedule and publish posts/videos from dashboard`

---

### Task 13: Status tabs on posts index (incl. Ideation)

**Files:**
- Modify: `app/Http/Controllers/Posts/PostsController.php` `index`
- Modify: `resources/js/pages/posts/index.tsx`
- Test: `tests/Feature/Posts/PostsControllerTest.php` (extend)

**Behavior:**
- Default `status` query = `draft` when missing (Studio default)
- Tabs: ideation, draft, ready, scheduled, posted, archived, dropped
- `status=ideation` → paginate `Idea::where(kind=post, status=open)` (confirm open status string in DB/factory — use whatever IdeaFactory uses for unpromoted ideas)
- Else filter posts by status
- Pass `counts` map for tab badges (single aggregated query / multiple count queries)
- Present ideation rows as `{ type: 'idea', id, human_id, title, score, trend }` vs posts `{ type: 'post', ... }`
- React: horizontal tablist; table rows; idea links to `dashboard.ideas.show`

- [ ] **Step 1–5:** TDD + UI; commit `feat: Studio-like status tabs on posts index`

---

### Task 14: Status tabs on videos index (incl. Ideation)

**Files:**
- Modify: `app/Http/Controllers/Videos/VideosController.php` `index`
- Modify: `resources/js/pages/videos/index.tsx`
- Test: `tests/Feature/Videos/VideosControllerTest.php`

Tabs: ideation, draft, pending, ready, recorded, scheduled, posted (label Published), archived, dropped. Default `pending`. Ideation = `kind=video` open ideas.

- [ ] **Step 1–5:** TDD + UI; commit `feat: Studio-like status tabs on videos index`

---

### Task 15: personal-content cutover + docs

**Files:**
- Modify: personal-content `web/server.py` — when env `CONTENT_MACHINE_PUBLISH=1` (or always after cutover), `/api/posts/publish` and video publish return 410 with message pointing to CM
- Modify: personal-content publish UI in `web/build.py` / site JS — hide PostSyncer publish panel; link to CM
- Modify: `docs/postsyncer-setup.md` (personal-content) + CM `docs/guides/api.md` or new `docs/guides/postsyncer.md`
- Modify: spec open points resolved in guide (API hosts confirmed)

- [ ] **Step 1:** Add CM guide with settings + schedule steps
- [ ] **Step 2:** Gate local publish behind flag defaulting to disabled once CM `publish_enabled` is live
- [ ] **Step 3:** Manual checklist in PR description (seed settings, schedule one draft post with Drive/attachment, one video with Drive URL)
- [ ] **Step 4:** Commit in each repo as appropriate

---

## Spec coverage self-review

| Spec requirement | Task |
|---|---|
| Workspace PostSyncer settings + encrypt key | 2, 10 |
| Drive URLs on videos | 1, 12 |
| Hybrid post media | 4 |
| Link upload client | 3 |
| Bilingual auto-split | 5, 6 |
| Queue jobs | 8, 9 |
| Dashboard Schedule/Publish | 12 |
| Status tabs + Ideation | 13, 14 |
| Seed from JSON | 11 |
| Local cutover | 15 |
| No agent API | (omitted intentionally) |
| No scheduled→posted poller | (omitted intentionally) |

## Placeholder scan

None intentional. Link-upload path locked to PostSyncer docs `POST /api/v1/media/upload/url`. Account list query param confirmed during Task 3 against https://docs.postsyncer.com — adjust client if docs differ; keep tests faked.

## Type consistency

- `publish_state` string enum shared on Post/Video
- `PostsyncerConfig` is the only settings reader for Actions/Client
- Jobs take `(Model $model, array $options)` and only call Actions
