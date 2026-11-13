<?php

namespace Tests\Feature\Socialreins;

use App\Actions\Postsyncer\EnqueuePostPublishAction;
use App\Actions\Socialreins\ApplySocialreinsWebhookAction;
use App\Actions\Socialreins\EnqueueSocialreinsPublishAction;
use App\Actions\Socialreins\PublishToSocialreinsAction;
use App\Actions\Socialreins\ReconcileSocialreinsGroupsAction;
use App\Actions\Socialreins\SettleSocialreinsSubjectAction;
use App\Actions\Socialreins\TakeDownSocialreinsAction;
use App\Jobs\PublishPostJob;
use App\Jobs\PublishSocialreinsJob;
use App\Models\Post;
use App\Models\SocialreinsGroup;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Socialreins\SocialreinsConfig;
use App\Support\Socialreins\SocialreinsResults;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakesPublicDns;
use Tests\TestCase;

class SocialreinsPublishTest extends TestCase
{
    use FakesPublicDns, RefreshDatabase;

    private const URL = 'https://brain.test/v1/post-groups';

    private const EN_SET = '11111111-1111-4111-8111-111111111111';

    private const BN_SET = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolveHostsTo();
    }

    private function workspace(bool $socialreins = true, ?string $tiktok = 'SELF_ONLY'): Workspace
    {
        $workspace = Workspace::factory()->create();
        PostsyncerConfig::write($workspace, ['post_types' => [
            'platforms' => [
                'facebook' => ['text' => 'on', 'photo' => 'on', 'reel' => 'on'],
                'linkedin' => ['text' => 'on', 'photo' => 'on'],
                'twitter' => ['text' => 'on', 'photo' => 'on', 'thread' => 'on', 'reel' => 'on'],
                'tiktok' => ['photo' => 'on', 'reel' => 'on'],
                'youtube' => ['reel' => 'on'],
                'instagram' => ['photo' => 'on', 'reel' => 'on'],
            ],
        ]]);
        SocialreinsConfig::write($workspace, [
            'publish_driver' => $socialreins ? 'socialreins' : 'postsyncer',
            'base_url' => 'https://brain.test/v1',
            'webhook_secret' => 'whsec_test',
            'tiktok_privacy_level' => $tiktok,
            'default_language' => 'english',
            'languages' => [
                'english' => ['set_id' => self::EN_SET, 'token' => 'sr_set_live_english'],
                'bangla' => ['set_id' => self::BN_SET, 'token' => 'sr_set_live_bangla'],
            ],
        ]);

        return $workspace->fresh();
    }

    private function makePost(Workspace $workspace, array $captions, array $attributes = []): Post
    {
        return Post::factory()->for($workspace)->create([
            'language' => 'en',
            'platforms' => ['facebook', 'twitter', 'linkedin', 'tiktok'],
            'captions' => ['English' => $captions],
            'image_drive_urls' => ['https://drive.google.com/file/d/abc/view'],
            'status' => 'ready',
            ...$attributes,
        ]);
    }

    /** @param  list<string>  $platforms */
    private function groupResponse(array $platforms, string $groupId = 'aaaaaaaa-0000-4000-8000-000000000001'): array
    {
        return [
            'group_id' => $groupId,
            'expected_targets' => count($platforms),
            'posts' => array_map(fn (string $p, int $i): array => [
                'id' => "bbbbbbbb-0000-4000-8000-00000000000{$i}",
                'platform' => $p,
                'status' => 'scheduled',
                'error' => null,
                'targets' => [],
            ], $platforms, array_keys($platforms)),
        ];
    }

    /** Enqueue like a user, then run the job body. */
    private function publish(Post|Video $subject, array $options = []): Post|Video
    {
        Queue::fake();
        $workspace = $subject->workspace;
        app(EnqueueSocialreinsPublishAction::class)->handle($subject, $workspace, $options);
        app(PublishToSocialreinsAction::class)->handle($subject, $options);

        return $subject->fresh();
    }

    /** @return list<Request> */
    private function sent(string $method = 'POST'): array
    {
        return Http::recorded()->map(fn (array $pair): Request => $pair[0])
            ->filter(fn (Request $r): bool => $r->method() === $method)->values()->all();
    }

    public function test_one_post_creates_one_group_per_language_with_its_own_token_set_and_key(): void
    {
        $this->fakeHttp([self::URL => fn (Request $r) => Http::response($this->groupResponse(
            ['facebook', 'x'],
            str_contains($r->header('Authorization')[0], 'english') ? 'aaaaaaaa-0000-4000-8000-0000000000e1' : 'aaaaaaaa-0000-4000-8000-0000000000b1',
        ), 201)]);
        $workspace = $this->workspace();
        $post = Post::factory()->for($workspace)->create([
            'language' => 'both',
            'platforms' => ['facebook', 'twitter'],
            'captions' => [
                'English' => ['facebook' => ['caption' => 'EN fb'], 'twitter' => ['caption' => 'EN x']],
                'Bangla' => ['facebook' => ['caption' => 'BN fb']],
            ],
            'image_drive_urls' => ['https://drive.google.com/file/d/abc/view'],
        ]);

        $this->publish($post);

        $requests = $this->sent();
        $this->assertCount(2, $requests);
        $english = collect($requests)->first(fn (Request $r) => $r->header('Authorization')[0] === 'Bearer sr_set_live_english');
        $bangla = collect($requests)->first(fn (Request $r) => $r->header('Authorization')[0] === 'Bearer sr_set_live_bangla');

        $this->assertSame(self::EN_SET, $english['social_set_id']);
        $this->assertSame(['facebook', 'x'], $english['platforms']);
        $this->assertSame('EN x', $english['overrides']['x']['body']);
        $this->assertSame(['https://drive.usercontent.google.com/download?id=abc&export=download&confirm=t'], $english['overrides']['facebook']['media']);
        $this->assertSame(self::BN_SET, $bangla['social_set_id']);
        $this->assertSame(['facebook'], $bangla['platforms']);
        $this->assertSame("cm:ws{$workspace->id}:post:{$post->id}:english:1", $english->header('Idempotency-Key')[0]);
        $this->assertSame("cm:ws{$workspace->id}:post:{$post->id}:bangla:1", $bangla->header('Idempotency-Key')[0]);

        $post->refresh();
        $this->assertSame('succeeded', $post->publish_state);
        $this->assertSame('scheduled', $post->status);
        $this->assertSame(2, SocialreinsGroup::query()->where('state', 'created')->count());
    }

    public function test_a_lost_answer_is_resent_byte_for_byte_with_the_same_key_days_later(): void
    {
        $this->fakeHttp([
            self::URL => Http::sequence()
                ->push(['error' => 'internal'], 500)
                ->push($this->groupResponse(['facebook']), 201),
            // 5xx answers are not stored: brain has nothing under the key, so the frozen body is resent.
            'brain.test/v1/idempotency/*' => Http::response(['error' => 'not_found'], 404),
        ]);
        $post = $this->makePost($this->workspace(), ['facebook' => ['caption' => 'Hello']], ['platforms' => ['facebook']]);

        $first = $this->publish($post);
        $this->assertSame('failed', $first->publish_state);
        $this->assertStringContainsString('SocialReins answered 500', $first->publish_error);
        $this->assertTrue($first->canRetryPublish());
        $this->assertSame('pending', SocialreinsGroup::query()->first()->state);

        $this->travel(3)->days();
        $this->publish($post);

        [$one, $two] = $this->sent();
        $this->assertSame($one->header('Idempotency-Key'), $two->header('Idempotency-Key'));
        $this->assertSame($one->body(), $two->body());
        $this->assertSame('succeeded', $post->fresh()->publish_state);
        $this->assertSame(1, SocialreinsGroup::query()->count());
    }

    public function test_a_refused_request_gets_a_new_key_on_the_next_attempt(): void
    {
        $this->fakeHttp([self::URL => Http::sequence()
            ->push(['error' => 'too_long', 'platform' => 'x', 'detail' => 'part 2 has 300 of 280 characters'], 422)
            ->push($this->groupResponse(['facebook']), 201)]);
        $post = $this->makePost($this->workspace(), ['facebook' => ['caption' => 'Hello']], ['platforms' => ['facebook']]);

        $first = $this->publish($post);

        $this->assertSame('failed', $first->publish_state);
        $this->assertStringContainsString('too_long', $first->publish_error);
        $this->assertStringContainsString('part 2 has 300 of 280 characters', $first->publish_error);
        $this->assertSame('rejected', SocialreinsGroup::query()->first()->state);

        $this->publish($post);

        [$one, $two] = $this->sent();
        $this->assertNotSame($one->header('Idempotency-Key'), $two->header('Idempotency-Key'));
        $this->assertStringEndsWith(':english:2', $two->header('Idempotency-Key')[0]);
        $this->assertSame('succeeded', $post->fresh()->publish_state);
    }

    public function test_tiktok_without_a_chosen_privacy_is_refused_and_nothing_is_sent(): void
    {
        $this->fakeHttp([]);
        $post = $this->makePost($this->workspace(tiktok: null), ['tiktok' => ['caption' => 'T']], ['platforms' => ['tiktok']]);

        $result = $this->publish($post);

        $this->assertSame('failed', $result->publish_state);
        $this->assertStringContainsString('TikTok visibility', $result->publish_error);
        Http::assertNothingSent();
        $this->assertSame(0, SocialreinsGroup::query()->count());
    }

    public function test_tiktok_gets_the_workspace_privacy_level(): void
    {
        $this->fakeHttp([self::URL => Http::response($this->groupResponse(['tiktok']), 201)]);
        $post = $this->makePost($this->workspace(tiktok: 'MUTUAL_FOLLOW_FRIENDS'), ['tiktok' => ['caption' => 'T']], ['platforms' => ['tiktok']]);

        $this->publish($post);

        $this->assertSame(['privacy_level' => 'MUTUAL_FOLLOW_FRIENDS'], $this->sent()[0]['overrides']['tiktok']['tiktok']);
    }

    public function test_video_goes_out_with_its_cover_on_platforms_that_take_one(): void
    {
        $this->fakeHttp([self::URL => Http::response($this->groupResponse(['facebook', 'tiktok', 'x']), 201)]);
        $workspace = $this->workspace();
        $video = Video::factory()->for($workspace)->create([
            'language' => 'en',
            'captions' => ['facebook' => 'Reel', 'tiktok' => 'Reel', 'twitter' => 'Reel'],
            'video_drive_url' => 'https://drive.google.com/file/d/vid/view',
            'cover_drive_url' => 'https://drive.google.com/file/d/cov/view',
        ]);

        $this->publish($video, ['platforms' => ['facebook', 'tiktok', 'twitter']]);

        $overrides = $this->sent()[0]['overrides'];
        $video = 'https://drive.usercontent.google.com/download?id=vid&export=download&confirm=t';
        $cover = 'https://drive.usercontent.google.com/download?id=cov&export=download&confirm=t';
        $this->assertSame([$video], $overrides['facebook']['media']);
        $this->assertSame($cover, $overrides['facebook']['cover']);
        $this->assertArrayNotHasKey('cover', $overrides['tiktok']);
        $this->assertArrayNotHasKey('cover', $overrides['x']);
        $this->assertStringStartsWith("cm:ws{$workspace->id}:video:", $this->sent()[0]->header('Idempotency-Key')[0]);
    }

    public function test_reconcile_picks_up_what_a_missed_webhook_would_have_said(): void
    {
        $reads = 0;
        $this->fakeHttp([
            self::URL => Http::response($this->groupResponse(['facebook']), 201),
            'brain.test/v1/post-groups/*' => function () use (&$reads) {
                $status = ++$reads === 1 ? 'scheduled' : 'published';

                return Http::response(['group_id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'expected_targets' => 1, 'posts' => [[
                    'id' => 'bbbbbbbb-0000-4000-8000-000000000000',
                    'platform' => 'facebook',
                    'status' => $status,
                    'targets' => [['id' => 't1', 'status' => $status === 'published' ? 'published' : 'pending', 'remote_url' => 'https://fb.test/1', 'error' => null, 'parts' => []]],
                ]]]);
            },
        ]);
        $post = $this->makePost($this->workspace(), ['facebook' => ['caption' => 'Hello']], ['platforms' => ['facebook']]);
        $this->publish($post);
        $this->assertSame('scheduled', $post->fresh()->status);

        $this->travel(10)->minutes();
        $this->assertSame(1, app(ReconcileSocialreinsGroupsAction::class)->handle());

        $this->assertSame('posted', $post->fresh()->status);
        $get = $this->sent('GET')[1];
        $this->assertStringEndsWith('/post-groups/aaaaaaaa-0000-4000-8000-000000000001', $get->url());
        $this->assertSame('Bearer sr_set_live_english', $get->header('Authorization')[0]);
    }

    public function test_take_down_deletes_with_a_key_and_retries_with_a_new_one_after_a_refusal(): void
    {
        $this->fakeHttp([
            self::URL => Http::response($this->groupResponse(['facebook']), 201),
            'brain.test/v1/posts/*' => Http::sequence()
                ->push(['error' => 'takedown_failed', 'message' => 'Delete it in the app.'], 422)
                ->push(null, 204),
        ]);
        $post = $this->makePost($this->workspace(), ['facebook' => ['caption' => 'Hello']], ['platforms' => ['facebook']]);
        $this->publish($post);

        $problems = app(TakeDownSocialreinsAction::class)->handle($post);
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('Delete it in the app.', $problems[0]);
        $this->assertSame('succeeded', $post->fresh()->publish_state);

        $this->assertSame([], app(TakeDownSocialreinsAction::class)->handle($post));

        [$one, $two] = $this->sent('DELETE');
        $this->assertStringEndsWith(':bbbbbbbb-0000-4000-8000-000000000000:1', $one->header('Idempotency-Key')[0]);
        $this->assertStringEndsWith(':bbbbbbbb-0000-4000-8000-000000000000:2', $two->header('Idempotency-Key')[0]);
        $post->refresh();
        $this->assertSame('idle', $post->publish_state);
        $this->assertSame('ready', $post->status);
    }

    public function test_a_redirect_is_not_followed_so_the_token_never_leaves(): void
    {
        $this->fakeHttp([
            self::URL => Http::response('', 302, ['Location' => 'https://evil.example/steal']),
            'evil.example/*' => Http::response([], 200),
        ]);
        $post = $this->makePost($this->workspace(), ['facebook' => ['caption' => 'Hello']], ['platforms' => ['facebook']]);

        $result = $this->publish($post);

        $this->assertSame('failed', $result->publish_state);
        $this->assertStringContainsString('answered 302', $result->publish_error);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'evil.example'));
    }

    public function test_every_call_rechecks_dns_and_sends_nothing_to_a_private_address(): void
    {
        $this->fakeHttp([self::URL => Http::response($this->groupResponse(['facebook']), 201), 'brain.test/*' => Http::response([], 200)]);
        $post = $this->makePost($this->workspace(), ['facebook' => ['caption' => 'Hello']], ['platforms' => ['facebook']]);
        $this->publish($post);
        $before = Http::recorded()->count();

        // The name now resolves inside the network (rebinding): create, reconcile and take down all refuse.
        $this->resolveHostsTo('192.168.1.10');
        $this->travel(10)->minutes();
        $this->assertSame(0, app(ReconcileSocialreinsGroupsAction::class)->handle());
        $problems = app(TakeDownSocialreinsAction::class)->handle($post->fresh());
        $this->assertStringContainsString('public network addresses', $problems[0]);

        $retry = $this->makePost($post->workspace, ['facebook' => ['caption' => 'Again']], ['platforms' => ['facebook']]);
        $this->assertSame('failed', $this->publish($retry)->publish_state);

        Http::assertSentCount($before);
    }

    public function test_the_driver_is_chosen_per_workspace_and_postsyncer_stays_the_default(): void
    {
        Queue::fake();
        $postsyncer = $this->workspace(socialreins: false);
        PostsyncerConfig::write($postsyncer, [
            'publish_enabled' => true,
            'api_key' => 'k',
            'default_language' => 'english',
            'languages' => ['english' => ['workspace_id' => '853', 'platforms' => []]],
        ]);
        $psPost = $this->makePost($postsyncer->fresh(), ['facebook' => ['caption' => 'x']]);
        (new EnqueuePostPublishAction)->handle($psPost, $postsyncer->fresh(), ['platforms' => ['facebook']]);

        $sr = $this->workspace();
        $srPost = $this->makePost($sr, ['facebook' => ['caption' => 'x']]);
        (new EnqueuePostPublishAction)->handle($srPost, $sr, ['platforms' => ['facebook']]);

        Queue::assertPushed(PublishPostJob::class, 1);
        Queue::assertPushed(PublishSocialreinsJob::class, 1);
        $this->assertSame('socialreins', $srPost->fresh()->publish_progress['driver']);
    }

    public function test_a_published_post_cannot_be_published_twice(): void
    {
        $this->fakeHttp([self::URL => Http::response($this->groupResponse(['facebook']), 201)]);
        $post = $this->makePost($this->workspace(), ['facebook' => ['caption' => 'Hello']], ['platforms' => ['facebook']]);
        $this->publish($post);

        $this->expectException(ValidationException::class);
        app(EnqueueSocialreinsPublishAction::class)->handle($post->fresh(), $post->workspace, []);
    }

    private const FB_POST = 'bbbbbbbb-0000-4000-8000-000000000000';

    private const X_POST = 'bbbbbbbb-0000-4000-8000-000000000001';

    private const GROUP = 'aaaaaaaa-0000-4000-8000-000000000001';

    /** What brain answers to GET /posts?group_id=, by group; tests change it between steps. */
    private array $brain = [];

    private bool $brainDown = false;

    /** @var list<mixed> queued answers to DELETE /posts/{id}; 204 when empty */
    private array $deleteReplies = [];

    /** @var list<mixed> queued answers to POST /post-groups/{id}/retry; 409 nothing_to_retry when empty */
    private array $retryReplies = [];

    /** @var array<string, mixed> answers to GET /idempotency/{key}, by key; 404 when absent */
    private array $lookups = [];

    /** @var array<string, int> expected_targets brain reports, by group (default: one per target, or per post with none yet) */
    private array $expected = [];

    private function groupBody(string $groupId): array
    {
        $posts = $this->brain[$groupId] ?? [];

        return [
            'group_id' => $groupId,
            'expected_targets' => $this->expected[$groupId] ?? array_sum(array_map(fn (array $p): int => max(1, count($p['targets'])), $posts)),
            'posts' => $posts,
        ];
    }

    private function brainPost(string $id, string $platform, string $status, array $targets = [], ?string $error = null): array
    {
        return ['id' => $id, 'platform' => $platform, 'status' => $status, 'error' => $error, 'targets' => $targets];
    }

    private function brainTarget(string $id, string $status, array $extra = []): array
    {
        return ['id' => $id, 'status' => $status, 'remote_id' => null, 'remote_url' => null, 'error' => null, 'parts' => [], ...$extra];
    }

    /** Create the group and make GET /posts?group_id= answer from $this->brain. */
    private function publishedGroup(array $platforms = ['facebook', 'twitter']): array
    {
        $workspace = $this->workspace();
        $post = $this->makePost($workspace, [
            'facebook' => ['caption' => 'FB'],
            'twitter' => ['caption' => 'X'],
        ], ['platforms' => $platforms]);
        $posts = array_values(array_filter([
            in_array('facebook', $platforms, true) ? $this->brainPost(self::FB_POST, 'facebook', 'scheduled') : null,
            in_array('twitter', $platforms, true) ? $this->brainPost(self::X_POST, 'x', 'scheduled') : null,
        ]));
        $this->brain = [self::GROUP => $posts];
        $this->deleteReplies = [];
        $this->retryReplies = [];
        $this->lookups = [];
        $this->expected = [];
        $this->fakeHttp([
            self::URL => Http::sequence()
                ->push(['group_id' => self::GROUP, 'expected_targets' => count($posts), 'posts' => $posts], 201)
                ->push($this->groupResponse(['x'], 'aaaaaaaa-0000-4000-8000-000000000002'), 201),
            // Laravel's fake runs every stub for every request (and keeps the first match), so
            // the closures below must tolerate requests meant for another stub.
            'brain.test/v1/post-groups/*/retry' => fn () => array_shift($this->retryReplies) ?? Http::response(['error' => 'nothing_to_retry'], 409),
            'brain.test/v1/post-groups/*' => fn (Request $r) => $this->brainDown
                ? Http::response(['error' => 'internal'], 500)
                : Http::response($this->groupBody(basename(parse_url($r->url(), PHP_URL_PATH)))),
            'brain.test/v1/idempotency/*' => fn (Request $r) => $this->lookups[urldecode(basename($r->url()))] ?? Http::response(['error' => 'not_found'], 404),
            'brain.test/v1/posts/*' => fn () => array_shift($this->deleteReplies) ?? Http::response(null, 204),
        ]);
        $this->publish($post);

        return [$workspace, $post];
    }

    private function retries(): array
    {
        return array_values(array_filter($this->sent(), fn (Request $r): bool => str_ends_with($r->url(), '/retry')));
    }

    private function creates(): array
    {
        return array_values(array_filter($this->sent(), fn (Request $r): bool => str_ends_with($r->url(), '/post-groups')));
    }

    private function retryAccepted(array $posts, array $skipped = [], int $expected = 1): PromiseInterface
    {
        return Http::response(['group_id' => self::GROUP, 'expected_targets' => $expected, 'posts' => $posts, 'skipped_targets' => $skipped], 202);
    }

    public function test_one_failed_account_of_two_is_retried_in_the_same_group_never_recreated(): void
    {
        [$workspace, $post] = $this->publishedGroup(['facebook']);
        // Two Facebook accounts in the set: one published, one failed. Brain posts to both.
        $this->brain[self::GROUP] = [
            $this->brainPost(self::FB_POST, 'facebook', 'published', [
                $this->brainTarget('t1', 'published', ['remote_id' => 'r1', 'remote_url' => 'https://fb.test/1']),
                $this->brainTarget('t2', 'failed', ['error' => 'token expired']),
            ]),
        ];

        app(ApplySocialreinsWebhookAction::class)->handle($workspace->id, $this->event('failed', self::FB_POST, 'facebook', 'token expired', target: 't2'));

        $post->refresh();
        $this->assertSame('failed', $post->publish_state);
        $this->assertStringContainsString('Partly published in SocialReins: facebook (group '.self::GROUP, $post->publish_error);
        $this->assertStringContainsString('nothing already published is sent again', $post->publish_error);

        $this->retryReplies = [$this->retryAccepted([$this->brainPost(self::FB_POST, 'facebook', 'queued', [
            $this->brainTarget('t1', 'published', ['remote_id' => 'r1']),
            $this->brainTarget('t2', 'pending'),
        ])], expected: 2)];
        $retried = $this->publish($post);

        $this->assertSame('succeeded', $retried->publish_state);
        $this->assertCount(1, $this->creates(), 'no second post group was created');
        [$retry] = $this->retries();
        $this->assertStringEndsWith('/post-groups/'.self::GROUP.'/retry', $retry->url());
        $this->assertSame('cm:ws'.$workspace->id.':retry:'.self::GROUP.':1', $retry->header('Idempotency-Key')[0]);
        $this->assertSame('Bearer sr_set_live_english', $retry->header('Authorization')[0]);

        // A stale webhook of the attempt that was retried does not bring the failure back.
        $this->brain[self::GROUP] = [$this->brainPost(self::FB_POST, 'facebook', 'queued', [
            $this->brainTarget('t1', 'published', ['remote_id' => 'r1']),
            $this->brainTarget('t2', 'pending'),
        ])];
        app(ApplySocialreinsWebhookAction::class)->handle($workspace->id, [...$this->event('failed', self::FB_POST, 'facebook', 'token expired', target: 't2'), 'created_at' => '2020-01-01T00:00:00Z']);
        $this->assertSame('succeeded', $post->fresh()->publish_state);

        $this->brain[self::GROUP] = [$this->brainPost(self::FB_POST, 'facebook', 'published', [
            $this->brainTarget('t1', 'published', ['remote_id' => 'r1']),
            $this->brainTarget('t2', 'published', ['remote_id' => 'r2']),
        ])];
        app(ApplySocialreinsWebhookAction::class)->handle($workspace->id, $this->event('published', self::FB_POST, 'facebook', target: 't2'));
        $this->assertSame('posted', $post->fresh()->status);
    }

    public function test_a_thread_that_failed_halfway_is_continued_by_the_group_retry(): void
    {
        [$workspace, $post] = $this->publishedGroup(['twitter']);
        $this->brain[self::GROUP] = [
            $this->brainPost(self::X_POST, 'x', 'failed', [
                $this->brainTarget('t1', 'failed', ['error' => 'part 3 failed', 'parts' => [['remote_id' => 'p1'], ['remote_id' => 'p2']]]),
            ], 'part 3 failed'),
        ];
        app(ApplySocialreinsWebhookAction::class)->handle($workspace->id, $this->event('failed', self::X_POST, 'x', 'part 3 failed'));
        $this->assertStringContainsString('Partly published', $post->fresh()->publish_error);

        $this->retryReplies = [$this->retryAccepted([$this->brainPost(self::X_POST, 'x', 'queued', [$this->brainTarget('t1', 'pending', ['parts' => [['remote_id' => 'p1'], ['remote_id' => 'p2']]])])])];
        $this->publish($post);

        $this->assertCount(1, $this->creates());
        $this->assertCount(1, $this->retries());
    }

    public function test_a_failed_platform_is_retried_in_place_and_each_attempt_has_its_own_key(): void
    {
        [$workspace, $post] = $this->publishedGroup();
        $this->brain[self::GROUP] = [
            $this->brainPost(self::FB_POST, 'facebook', 'published', [$this->brainTarget('t1', 'published', ['remote_id' => 'r1'])]),
            $this->brainPost(self::X_POST, 'x', 'failed', [$this->brainTarget('t2', 'failed', ['error' => 'rate limited'])]),
        ];
        app(ApplySocialreinsWebhookAction::class)->handle($workspace->id, $this->event('failed', self::X_POST, 'x', 'rate limited', target: 't2'));

        $post->refresh();
        $this->assertSame('failed', $post->publish_state);
        $this->assertStringContainsString('twitter (rate limited)', $post->publish_error);
        $this->assertStringNotContainsString('facebook', $post->publish_error);

        $queued = $this->retryAccepted([
            $this->brainPost(self::FB_POST, 'facebook', 'published', [$this->brainTarget('t1', 'published', ['remote_id' => 'r1'])]),
            $this->brainPost(self::X_POST, 'x', 'queued', [$this->brainTarget('t2', 'pending')]),
        ], expected: 2);
        $this->retryReplies = [$queued];
        $this->publish($post);

        // It fails again, and the second retry gets a new key.
        $this->brain[self::GROUP][1] = $this->brainPost(self::X_POST, 'x', 'failed', [$this->brainTarget('t2', 'failed', ['error' => 'rate limited again'])]);
        $this->travel(1)->minutes();
        app(ApplySocialreinsWebhookAction::class)->handle($workspace->id, [...$this->event('failed', self::X_POST, 'x', 'rate limited again', target: 't2'), 'created_at' => now()->addMinute()->toIso8601String()]);
        $this->assertSame('failed', $post->fresh()->publish_state);
        $this->retryReplies = [$queued];
        $this->publish($post);

        $keys = array_map(fn (Request $r): string => $r->header('Idempotency-Key')[0], $this->retries());
        $this->assertSame(["cm:ws{$workspace->id}:retry:".self::GROUP.':1', "cm:ws{$workspace->id}:retry:".self::GROUP.':2'], $keys);
        $this->assertCount(1, $this->creates());
    }

    public function test_targets_brain_cannot_retry_are_shown_as_check_the_platform_and_not_retried_again(): void
    {
        [$workspace, $post] = $this->publishedGroup(['twitter']);
        $this->brain[self::GROUP] = [
            $this->brainPost(self::X_POST, 'x', 'failed', [$this->brainTarget('t1', 'failed', ['error' => 'Timed out; it may have posted, check the account.'])]),
        ];
        app(ApplySocialreinsWebhookAction::class)->handle($workspace->id, $this->event('failed', self::X_POST, 'x', 'Timed out; it may have posted, check the account.'));
        $this->retryReplies = [Http::response([
            'error' => 'outcome_unknown', 'message' => 'Every failed target may already have posted.',
            'targets' => [['target_id' => 't1', 'post_id' => self::X_POST, 'reason' => 'outcome_unknown', 'error' => 'Timed out']],
        ], 409)];

        $result = $this->publish($post);

        $this->assertSame('failed', $result->publish_state);
        $this->assertStringContainsString('Check the platform', $result->publish_error);
        $this->assertStringContainsString('outcome unknown', $result->publish_error);

        // Asking again does not call brain's retry for them.
        $again = $this->publish($post);
        $this->assertStringContainsString('Check the platform', $again->publish_error);
        $this->assertCount(1, $this->retries());
    }

    public function test_skipped_targets_of_an_accepted_retry_stay_flagged_while_the_rest_is_requeued(): void
    {
        [$workspace, $post] = $this->publishedGroup(['facebook']);
        $this->brain[self::GROUP] = [$this->brainPost(self::FB_POST, 'facebook', 'published', [
            $this->brainTarget('t1', 'failed', ['error' => 'token expired']),
            $this->brainTarget('t2', 'failed', ['error' => 'Timed out; it may have posted']),
        ])];
        app(ApplySocialreinsWebhookAction::class)->handle($workspace->id, $this->event('failed', self::FB_POST, 'facebook', target: 't1'));
        $this->retryReplies = [$this->retryAccepted(
            [$this->brainPost(self::FB_POST, 'facebook', 'queued', [$this->brainTarget('t1', 'pending'), $this->brainTarget('t2', 'failed', ['error' => 'Timed out; it may have posted'])])],
            [['target_id' => 't2', 'post_id' => self::FB_POST, 'reason' => 'outcome_unknown']],
            expected: 2,
        )];

        $this->publish($post);

        $stored = SocialreinsGroup::query()->first()->results[self::FB_POST]['targets'];
        $this->assertTrue($stored['t2']['unknown']);
        $this->assertSame('pending', $stored['t1']['status']);
    }

    public function test_a_retry_is_refused_when_brain_cannot_be_asked_what_is_already_live(): void
    {
        [$workspace, $post] = $this->publishedGroup();
        $this->brain[self::GROUP] = [
            $this->brainPost(self::FB_POST, 'facebook', 'published', [$this->brainTarget('t1', 'published')]),
            $this->brainPost(self::X_POST, 'x', 'failed', [$this->brainTarget('t2', 'failed')]),
        ];
        app(ApplySocialreinsWebhookAction::class)->handle($workspace->id, $this->event('failed', self::X_POST, 'x', target: 't2'));
        $this->brainDown = true;

        $result = $this->publish($post);

        $this->assertStringContainsString('Nothing was retried', $result->publish_error);
        $this->assertSame([], $this->retries());
    }

    public function test_the_first_of_two_accounts_publishing_does_not_mark_the_post_posted_and_reconcile_finishes_it(): void
    {
        [$workspace, $post] = $this->publishedGroup(['facebook']);
        // The webhook is about t1 only; brain's own list shows t2 still publishing.
        $this->brain[self::GROUP] = [$this->brainPost(self::FB_POST, 'facebook', 'queued', [
            $this->brainTarget('t1', 'published', ['remote_id' => 'r1']),
            $this->brainTarget('t2', 'publishing'),
        ])];
        app(ApplySocialreinsWebhookAction::class)->handle($workspace->id, $this->event('published', self::FB_POST, 'facebook', target: 't1'));
        $this->assertSame('scheduled', $post->fresh()->status);

        // Even if the snapshot could not be fetched, a lone event never settles the post.
        $this->brainDown = true;
        app(ApplySocialreinsWebhookAction::class)->handle($workspace->id, $this->event('published', self::FB_POST, 'facebook', target: 't2'));
        $this->assertSame('scheduled', $post->fresh()->status);

        $this->brainDown = false;
        $this->brain[self::GROUP] = [$this->brainPost(self::FB_POST, 'facebook', 'published', [
            $this->brainTarget('t1', 'published', ['remote_id' => 'r1']),
            $this->brainTarget('t2', 'published', ['remote_id' => 'r2']),
        ])];
        $this->travel(10)->minutes();
        $this->assertSame(1, app(ReconcileSocialreinsGroupsAction::class)->handle());
        $this->assertSame('posted', $post->fresh()->status);
    }

    public function test_a_group_is_not_settled_until_its_expected_targets_have_all_reported(): void
    {
        [$workspace, $post] = $this->publishedGroup(['facebook']);
        // Brain expects 2 targets (two accounts) but has only created and finished one so far.
        $this->expected[self::GROUP] = 2;
        $this->brain[self::GROUP] = [$this->brainPost(self::FB_POST, 'facebook', 'published', [$this->brainTarget('t1', 'published', ['remote_id' => 'r1'])])];
        $group = SocialreinsGroup::query()->first();
        app(ReconcileSocialreinsGroupsAction::class)->reconcileGroup($group);
        app(SettleSocialreinsSubjectAction::class)->handle($post->fresh());

        $this->assertSame(2, $group->fresh()->expected_targets);
        $this->assertSame('scheduled', $post->fresh()->status);

        $group->mutateResults(function (array $r): array {
            $r[self::FB_POST]['targets']['t2'] = SocialreinsResults::target($this->brainTarget('t2', 'published'), time());

            return $r;
        });
        app(SettleSocialreinsSubjectAction::class)->handle($post->fresh());
        $this->assertSame('posted', $post->fresh()->status);
    }

    public function test_an_older_snapshot_never_overwrites_a_newer_webhook_result(): void
    {
        $this->publishedGroup(['facebook']);
        $group = SocialreinsGroup::query()->first();
        $older = SocialreinsResults::fromBrainPost(
            $this->brainPost(self::FB_POST, 'facebook', 'queued', [$this->brainTarget('t1', 'publishing')]),
            at: 1_000,
        );

        $group->mutateResults(fn (array $r) => SocialreinsResults::withTargetEvent($r, $this->event('published', self::FB_POST, 'facebook')['data'], 2_000));
        // A reconcile that fetched before the webhook but saved after it:
        $group->mutateResults(function (array $r) use ($older) {
            $r[self::FB_POST] = SocialreinsResults::mergePost($r[self::FB_POST], $older);

            return $r;
        });

        $this->assertSame('published', $group->fresh()->results[self::FB_POST]['targets']['t1']['status']);
    }

    private function lostAnswer(): Post
    {
        $this->fakeHttp([
            self::URL => Http::response(['error' => 'internal'], 500),
            'brain.test/v1/idempotency/*' => fn (Request $r) => $this->lookups[urldecode(basename($r->url()))] ?? Http::response(['error' => 'not_found'], 404),
            'brain.test/v1/post-groups/*' => fn () => Http::response($this->groupBody(self::GROUP)),
            'brain.test/v1/posts/*' => Http::response(null, 204),
        ]);
        $post = $this->makePost($this->workspace(), ['facebook' => ['caption' => 'Hello']], ['platforms' => ['facebook']]);
        $this->publish($post);
        $this->assertSame('pending', SocialreinsGroup::query()->first()->state);

        return $post;
    }

    public function test_a_lost_answer_is_resolved_through_the_idempotency_lookup_without_resending(): void
    {
        $post = $this->lostAnswer();
        $key = SocialreinsGroup::query()->first()->idempotency_key;
        $this->brain = [self::GROUP => [$this->brainPost(self::FB_POST, 'facebook', 'scheduled')]];
        $this->lookups[$key] = Http::response([
            'key' => $key, 'method' => 'POST', 'path' => '/post-groups', 'status' => 'done', 'response_status' => 201,
            'response_body' => $this->groupResponse(['facebook']),
        ]);

        $result = $this->publish($post);

        $group = SocialreinsGroup::query()->first();
        $this->assertSame('created', $group->state);
        $this->assertSame(self::GROUP, $group->group_id);
        $this->assertSame('succeeded', $result->publish_state);
        $this->assertCount(1, $this->creates(), 'the request was not sent again');
    }

    public function test_a_lookup_that_is_still_in_progress_keeps_the_row_pending_and_does_not_resend(): void
    {
        $post = $this->lostAnswer();
        $key = SocialreinsGroup::query()->first()->idempotency_key;
        $this->lookups[$key] = Http::response(['key' => $key, 'status' => 'in_progress', 'response_status' => null, 'response_body' => null]);

        $result = $this->publish($post);

        $this->assertSame('pending', SocialreinsGroup::query()->first()->state);
        $this->assertStringContainsString('still processing', $result->publish_error);
        $this->assertCount(1, $this->creates());
    }

    public function test_a_lookup_that_found_a_stored_refusal_closes_the_row(): void
    {
        $post = $this->lostAnswer();
        $key = SocialreinsGroup::query()->first()->idempotency_key;
        $this->lookups[$key] = Http::response(['key' => $key, 'status' => 'done', 'response_status' => 422, 'response_body' => ['error' => 'too_long', 'platform' => 'x']]);

        $result = $this->publish($post);

        $this->assertSame('rejected', SocialreinsGroup::query()->first()->state);
        $this->assertStringContainsString('too_long', $result->publish_error);
    }

    public function test_take_down_resolves_a_lost_answer_first_and_then_works(): void
    {
        $post = $this->lostAnswer();
        $key = SocialreinsGroup::query()->first()->idempotency_key;
        $this->brain = [self::GROUP => [$this->brainPost(self::FB_POST, 'facebook', 'published', [$this->brainTarget('t1', 'published', ['remote_id' => 'r1'])])]];
        $this->lookups[$key] = Http::response([
            'key' => $key, 'status' => 'done', 'response_status' => 201, 'response_body' => $this->groupResponse(['facebook']),
        ]);

        $problems = app(TakeDownSocialreinsAction::class)->handle($post->fresh());

        $this->assertSame([], $problems);
        $this->assertCount(1, $this->sent('DELETE'), 'the group it found was taken down');
        $this->assertSame('idle', $post->fresh()->publish_state);
    }

    public function test_take_down_with_a_lookup_that_cannot_say_is_refused_and_not_reset(): void
    {
        $post = $this->lostAnswer();
        $key = SocialreinsGroup::query()->first()->idempotency_key;
        $this->lookups[$key] = Http::response(['key' => $key, 'status' => 'in_progress']);

        $problems = app(TakeDownSocialreinsAction::class)->handle($post->fresh());

        $this->assertStringContainsString('status unknown', $problems[0]);
        $this->assertSame([], $this->sent('DELETE'));
        $this->assertSame('failed', $post->fresh()->publish_state);
    }

    public function test_take_down_when_brain_stored_nothing_under_the_key_finds_nothing_to_take_down(): void
    {
        $post = $this->lostAnswer();

        $this->assertSame([], app(TakeDownSocialreinsAction::class)->handle($post->fresh()));
        $this->assertSame([], $this->sent('DELETE'));
        $this->assertSame('rejected', SocialreinsGroup::query()->first()->state);
    }

    public function test_take_down_skips_failed_posts_waits_for_queued_ones_and_retries_a_409(): void
    {
        [$workspace, $post] = $this->publishedGroup();
        $this->brain[self::GROUP] = [
            $this->brainPost(self::FB_POST, 'facebook', 'failed', [$this->brainTarget('t1', 'failed')]),
            $this->brainPost(self::X_POST, 'x', 'queued', [$this->brainTarget('t2', 'publishing')]),
        ];
        app(ApplySocialreinsWebhookAction::class)->handle($workspace->id, $this->event('failed', self::FB_POST, 'facebook', target: 't1'));

        $problems = app(TakeDownSocialreinsAction::class)->handle($post->fresh());

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('still publishing', $problems[0]);
        $this->assertSame([], $this->sent('DELETE'), 'neither the failed nor the queued post was deleted');
        $this->assertNotSame('idle', $post->fresh()->publish_state);

        // The queued post settles as published; brain answers 409 once, then deletes.
        $this->brain[self::GROUP][1] = $this->brainPost(self::X_POST, 'x', 'published', [$this->brainTarget('t2', 'published')]);
        app(ReconcileSocialreinsGroupsAction::class)->reconcileGroup(SocialreinsGroup::query()->first());
        $this->deleteReplies = [Http::response(['error' => 'not_deletable'], 409)];

        $first = app(TakeDownSocialreinsAction::class)->handle($post->fresh());
        $this->assertStringContainsString('Try again later', $first[0]);
        $this->assertSame([], app(TakeDownSocialreinsAction::class)->handle($post->fresh()));
        $this->assertSame('idle', $post->fresh()->publish_state);
        $keys = array_map(fn (Request $r) => $r->header('Idempotency-Key')[0], $this->sent('DELETE'));
        $this->assertCount(2, array_unique($keys));
        $statuses = Http::recorded()->filter(fn (array $pair): bool => $pair[0]->method() === 'DELETE')
            ->map(fn (array $pair): int => $pair[1]->status())->values()->all();
        $this->assertSame([409, 204], $statuses);
    }

    public function test_a_reused_idempotency_key_keeps_the_row_pending_and_never_gets_a_new_key(): void
    {
        $this->fakeHttp([
            self::URL => Http::response(['error' => 'idempotency_key_reused'], 422),
            'brain.test/v1/idempotency/*' => Http::response(['error' => 'not_found'], 404),
        ]);
        $post = $this->makePost($this->workspace(), ['facebook' => ['caption' => 'Hello']], ['platforms' => ['facebook']]);

        $first = $this->publish($post);
        $this->assertStringContainsString('idempotency_key_reused', $first->publish_error);
        $this->assertSame('pending', SocialreinsGroup::query()->first()->state);

        $this->publish($post);

        [$one, $two] = $this->sent();
        $this->assertSame($one->header('Idempotency-Key'), $two->header('Idempotency-Key'));
        $this->assertSame(1, SocialreinsGroup::query()->count());
    }

    public function test_a_reused_key_is_resolved_by_asking_what_the_key_made(): void
    {
        $this->fakeHttp([
            self::URL => Http::response(['error' => 'idempotency_key_reused'], 422),
            'brain.test/v1/idempotency/*' => fn (Request $r) => Http::response([
                'key' => urldecode(basename($r->url())), 'status' => 'done', 'response_status' => 201, 'response_body' => $this->groupResponse(['facebook']),
            ]),
        ]);
        $post = $this->makePost($this->workspace(), ['facebook' => ['caption' => 'Hello']], ['platforms' => ['facebook']]);

        $this->publish($post);

        $this->assertSame('created', SocialreinsGroup::query()->first()->state);
        $this->assertCount(1, $this->creates());
    }

    public function test_a_tiktok_target_without_settings_is_a_clear_workspace_settings_error(): void
    {
        $this->fakeHttp([self::URL => Http::response(['error' => 'tiktok_settings_required', 'platform' => 'tiktok'], 422)]);
        $post = $this->makePost($this->workspace(), ['tiktok' => ['caption' => 'T']], ['platforms' => ['tiktok']]);

        $result = $this->publish($post);

        $this->assertSame('failed', $result->publish_state);
        $this->assertStringContainsString('Choose one in Settings > SocialReins', $result->publish_error);
        $this->assertSame('rejected', SocialreinsGroup::query()->first()->state);
    }

    /** @return array<string, mixed> */
    private const GROUP_2 = 'aaaaaaaa-0000-4000-8000-000000000002';

    /** @var list<mixed> queued answers to POST /post-groups */
    private array $createReplies = [];

    /** @return array<string, mixed> one element of brain's `skipped` */
    private function skippedX(string $detail = 'X text is 300 characters, the limit is 280.'): array
    {
        return ['platform' => 'x', 'account_ids' => ['acc1'], 'ok' => false, 'problems' => [
            ['code' => 'too_long', 'field' => 'body', 'limit' => 280, 'actual' => 300, 'detail' => $detail],
        ]];
    }

    /** Facebook and X are sent; brain creates Facebook and leaves X out as invalid. */
    private function groupWithSkippedX(): array
    {
        $workspace = $this->workspace();
        $post = $this->makePost($workspace, ['facebook' => ['caption' => 'FB'], 'twitter' => ['caption' => 'X too long']], ['platforms' => ['facebook', 'twitter']]);
        $facebook = $this->brainPost(self::FB_POST, 'facebook', 'scheduled');
        $this->brain = [self::GROUP => [$facebook]];
        $this->createReplies = [Http::response(['group_id' => self::GROUP, 'expected_targets' => 1, 'posts' => [$facebook], 'skipped' => [$this->skippedX()]], 201)];
        $this->fakeHttp([
            self::URL => fn () => array_shift($this->createReplies) ?? Http::response(['error' => 'internal'], 500),
            'brain.test/v1/post-groups/*/retry' => Http::response(['error' => 'nothing_to_retry'], 409),
            'brain.test/v1/post-groups/*' => fn (Request $r) => Http::response($this->groupBody(basename(parse_url($r->url(), PHP_URL_PATH)))),
            'brain.test/v1/posts/*' => Http::response(null, 204),
        ]);

        return [$workspace, $post, $this->publish($post)];
    }

    public function test_a_platform_brain_left_out_fails_alone_and_its_record_survives_reconcile_webhooks_and_take_down(): void
    {
        [$workspace, $post, $result] = $this->groupWithSkippedX();

        $this->assertSame('skip', $this->creates()[0]['on_invalid']);
        $this->assertSame('failed', $result->publish_state);
        $this->assertSame('scheduled', $result->status, 'facebook is scheduled as usual');
        $this->assertStringContainsString('twitter (X text is 300 characters, the limit is 280.)', $result->publish_error);
        $this->assertStringNotContainsString('facebook', $result->publish_error);

        $group = SocialreinsGroup::query()->sole();
        $this->assertSame(['platform' => 'twitter', 'status' => 'failed', 'skipped' => true], array_intersect_key($group->results['skipped:twitter'], array_flip(['platform', 'status', 'skipped'])));

        $shown = collect(SocialreinsResults::presentGroupsForApi($result))->keyBy(fn (array $g): string => $g['platforms'][0]);
        $this->assertSame('failed', $shown['twitter']['status']);
        $this->assertNull($shown['twitter']['post_id']);
        $this->assertSame('Publishing failed. Inspect the dashboard for details.', $shown['twitter']['detail']);
        $this->assertSame('scheduled', $shown['facebook']['status']);

        // Facebook goes out: the reconcile and the webhook rewrite its result, not the skipped record.
        $this->brain[self::GROUP] = [$this->brainPost(self::FB_POST, 'facebook', 'published', [$this->brainTarget('t1', 'published', ['remote_id' => 'r1'])])];
        $this->assertTrue(app(ReconcileSocialreinsGroupsAction::class)->reconcileGroup($group));
        app(ApplySocialreinsWebhookAction::class)->handle($workspace->id, $this->event('published', self::FB_POST, 'facebook'));

        $this->assertSame('published', $group->fresh()->results[self::FB_POST]['status']);
        $this->assertTrue($group->fresh()->results['skipped:twitter']['skipped']);
        $this->assertSame('failed', $post->fresh()->publish_state);
        $this->assertStringContainsString('twitter (X text is 300', $post->fresh()->publish_error);

        // Nothing exists at SocialReins for it, so a take down only deletes Facebook.
        $this->assertSame([], app(TakeDownSocialreinsAction::class)->handle($post->fresh()));
        $this->assertCount(1, $this->sent('DELETE'));
    }

    public function test_a_retry_sends_only_the_left_out_platform_in_a_new_group_with_a_new_key(): void
    {
        [$workspace, $post] = $this->groupWithSkippedX();

        // Still too long: a group with nothing valid is refused, and the new reason is shown.
        $this->createReplies = [Http::response(['error' => 'too_long', 'platform' => 'x', 'message' => 'Still too long.'], 422)];
        $again = $this->publish($post);
        $this->assertSame('failed', $again->publish_state);
        $this->assertStringContainsString('Still too long.', $again->publish_error);

        // Fixed: the retry sends the post as it is now.
        $post->forceFill(['captions' => ['English' => ['facebook' => ['caption' => 'FB'], 'twitter' => ['caption' => 'X short']]]])->save();
        $x = $this->brainPost(self::X_POST, 'x', 'scheduled');
        $this->brain[self::GROUP_2] = [$x];
        $this->createReplies = [Http::response(['group_id' => self::GROUP_2, 'expected_targets' => 1, 'posts' => [$x], 'skipped' => []], 201)];
        $retried = $this->publish($post);

        $this->assertSame('succeeded', $retried->publish_state);
        $this->assertNull($retried->publish_error);
        $creates = $this->creates();
        $this->assertCount(3, $creates);
        foreach ([1, 2] as $attempt) {
            $this->assertSame(['x'], $creates[$attempt]['platforms'], 'facebook is live and never sent again');
            $this->assertSame(['x'], array_keys($creates[$attempt]['overrides']));
        }
        $this->assertSame('X short', $creates[2]['overrides']['x']['body']);
        $this->assertSame(
            ["cm:ws{$workspace->id}:post:{$post->id}:english:1", "cm:ws{$workspace->id}:post:{$post->id}:english:2", "cm:ws{$workspace->id}:post:{$post->id}:english:3"],
            array_map(fn (Request $r): string => $r->header('Idempotency-Key')[0], $creates),
        );
        $this->assertSame([], $this->retries(), 'the group retry has no post to act on');

        $platforms = SocialreinsResults::byPlatform(SocialreinsResults::rowsOf($post));
        $this->assertSame(self::X_POST, $platforms['english:twitter']['post_id']);
        $this->assertFalse($platforms['english:twitter']['skipped']);

        // Settled and clean now: nothing is sent a second time.
        try {
            app(EnqueueSocialreinsPublishAction::class)->handle($post->fresh(), $workspace, []);
            $this->fail('A clean publish must not be sent again.');
        } catch (ValidationException) {
            $this->assertCount(3, $this->creates());
        }
    }

    public function test_a_lost_answer_found_by_the_lookup_records_what_brain_left_out(): void
    {
        $post = $this->lostAnswer();
        $key = SocialreinsGroup::query()->first()->idempotency_key;
        $this->brain = [self::GROUP => [$this->brainPost(self::FB_POST, 'facebook', 'scheduled')]];
        $skipped = $this->skippedX();
        unset($skipped['problems'][0]['detail']);
        $this->lookups[$key] = Http::response([
            'key' => $key, 'method' => 'POST', 'path' => '/post-groups', 'status' => 'done', 'response_status' => 201,
            'response_body' => [...$this->groupResponse(['facebook']), 'skipped' => [$skipped]],
        ]);

        $result = $this->publish($post);

        $this->assertTrue(SocialreinsGroup::query()->sole()->results['skipped:twitter']['skipped']);
        $this->assertSame('failed', $result->publish_state);
        $this->assertStringContainsString('twitter (too_long)', $result->publish_error);
        $this->assertCount(1, $this->creates(), 'the request was not sent again');
    }

    private function event(string $status, string $postId, string $platform, ?string $error = null, string $group = 'aaaaaaaa-0000-4000-8000-000000000001', string $target = 't1'): array
    {
        return [
            'id' => 'cccccccc-0000-4000-8000-000000000000',
            'type' => "post.target.{$status}",
            'data' => [
                'post_id' => $postId, 'group_id' => $group, 'target_id' => $target,
                'platform' => $platform, 'status' => $status, 'remote_url' => 'https://x.test/1', 'error' => $error,
            ],
        ];
    }
}
