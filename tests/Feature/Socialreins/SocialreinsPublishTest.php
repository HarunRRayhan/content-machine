<?php

namespace Tests\Feature\Socialreins;

use App\Actions\Postsyncer\EnqueuePostPublishAction;
use App\Actions\Socialreins\ApplySocialreinsWebhookAction;
use App\Actions\Socialreins\EnqueueSocialreinsPublishAction;
use App\Actions\Socialreins\PublishToSocialreinsAction;
use App\Actions\Socialreins\ReconcileSocialreinsGroupsAction;
use App\Actions\Socialreins\TakeDownSocialreinsAction;
use App\Jobs\PublishPostJob;
use App\Jobs\PublishSocialreinsJob;
use App\Models\Post;
use App\Models\SocialreinsGroup;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Socialreins\SocialreinsConfig;
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
        Http::fake([self::URL => fn (Request $r) => Http::response($this->groupResponse(
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
        Http::fake([self::URL => Http::sequence()
            ->push(['error' => 'internal'], 500)
            ->push($this->groupResponse(['facebook']), 201)]);
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
        Http::fake([self::URL => Http::sequence()
            ->push(['error' => 'too_long', 'platform' => 'x'], 422)
            ->push($this->groupResponse(['facebook']), 201)]);
        $post = $this->makePost($this->workspace(), ['facebook' => ['caption' => 'Hello']], ['platforms' => ['facebook']]);

        $first = $this->publish($post);

        $this->assertSame('failed', $first->publish_state);
        $this->assertStringContainsString('too_long', $first->publish_error);
        $this->assertSame('rejected', SocialreinsGroup::query()->first()->state);

        $this->publish($post);

        [$one, $two] = $this->sent();
        $this->assertNotSame($one->header('Idempotency-Key'), $two->header('Idempotency-Key'));
        $this->assertStringEndsWith(':english:2', $two->header('Idempotency-Key')[0]);
        $this->assertSame('succeeded', $post->fresh()->publish_state);
    }

    public function test_partial_failure_is_reported_and_a_retry_sends_only_the_failed_platform(): void
    {
        Http::fake([self::URL => Http::sequence()
            ->push($this->groupResponse(['facebook', 'x']), 201)
            ->push($this->groupResponse(['x'], 'aaaaaaaa-0000-4000-8000-000000000002'), 201)]);
        $workspace = $this->workspace();
        $post = $this->makePost($workspace, [
            'facebook' => ['caption' => 'FB'],
            'twitter' => ['caption' => 'X'],
        ], ['platforms' => ['facebook', 'twitter']]);

        $this->publish($post);

        $webhook = app(ApplySocialreinsWebhookAction::class);
        $webhook->handle($workspace->id, $this->event('published', 'bbbbbbbb-0000-4000-8000-000000000000', 'facebook'));
        $this->assertSame('scheduled', $post->fresh()->status);

        $webhook->handle($workspace->id, $this->event('failed', 'bbbbbbbb-0000-4000-8000-000000000001', 'x', error: 'rate limited'));

        $post->refresh();
        $this->assertSame('failed', $post->publish_state);
        $this->assertStringContainsString('twitter (rate limited)', $post->publish_error);
        $this->assertStringNotContainsString('facebook', $post->publish_error);

        $this->publish($post);

        $retry = $this->sent()[1];
        $this->assertSame(['x'], $retry['platforms']);
        $this->assertStringEndsWith(':english:2', $retry->header('Idempotency-Key')[0]);

        $webhook->handle($workspace->id, $this->event('published', 'bbbbbbbb-0000-4000-8000-000000000000', 'x', group: 'aaaaaaaa-0000-4000-8000-000000000002', target: 't2'));
        $post->refresh();
        $this->assertSame('succeeded', $post->publish_state);
        $this->assertSame('posted', $post->status);
    }

    public function test_tiktok_without_a_chosen_privacy_is_refused_and_nothing_is_sent(): void
    {
        Http::fake();
        $post = $this->makePost($this->workspace(tiktok: null), ['tiktok' => ['caption' => 'T']], ['platforms' => ['tiktok']]);

        $result = $this->publish($post);

        $this->assertSame('failed', $result->publish_state);
        $this->assertStringContainsString('TikTok visibility', $result->publish_error);
        Http::assertNothingSent();
        $this->assertSame(0, SocialreinsGroup::query()->count());
    }

    public function test_tiktok_gets_the_workspace_privacy_level(): void
    {
        Http::fake([self::URL => Http::response($this->groupResponse(['tiktok']), 201)]);
        $post = $this->makePost($this->workspace(tiktok: 'MUTUAL_FOLLOW_FRIENDS'), ['tiktok' => ['caption' => 'T']], ['platforms' => ['tiktok']]);

        $this->publish($post);

        $this->assertSame(['privacy_level' => 'MUTUAL_FOLLOW_FRIENDS'], $this->sent()[0]['overrides']['tiktok']['tiktok']);
    }

    public function test_video_goes_out_with_its_cover_on_platforms_that_take_one(): void
    {
        Http::fake([self::URL => Http::response($this->groupResponse(['facebook', 'tiktok', 'x']), 201)]);
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
        Http::fake([
            self::URL => Http::response($this->groupResponse(['facebook']), 201),
            'brain.test/v1/posts*' => Http::response([[
                'id' => 'bbbbbbbb-0000-4000-8000-000000000000',
                'platform' => 'facebook',
                'status' => 'published',
                'targets' => [['id' => 't1', 'status' => 'published', 'remote_url' => 'https://fb.test/1', 'error' => null]],
            ]]),
        ]);
        $post = $this->makePost($this->workspace(), ['facebook' => ['caption' => 'Hello']], ['platforms' => ['facebook']]);
        $this->publish($post);

        $this->travel(10)->minutes();
        $this->assertSame(1, app(ReconcileSocialreinsGroupsAction::class)->handle());

        $this->assertSame('posted', $post->fresh()->status);
        $get = $this->sent('GET')[0];
        $this->assertSame('aaaaaaaa-0000-4000-8000-000000000001', $get['group_id']);
        $this->assertSame('Bearer sr_set_live_english', $get->header('Authorization')[0]);
    }

    public function test_take_down_deletes_with_a_key_and_retries_with_a_new_one_after_a_refusal(): void
    {
        Http::fake([
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
        Http::fake([
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
        Http::fake([self::URL => Http::response($this->groupResponse(['facebook']), 201), 'brain.test/*' => Http::response([], 200)]);
        $post = $this->makePost($this->workspace(), ['facebook' => ['caption' => 'Hello']], ['platforms' => ['facebook']]);
        $this->publish($post);
        $sent = count($this->sent());

        // The name now resolves inside the network (rebinding): create, reconcile and take down all refuse.
        $this->resolveHostsTo('192.168.1.10');
        $this->travel(10)->minutes();
        $this->assertSame(0, app(ReconcileSocialreinsGroupsAction::class)->handle());
        $problems = app(TakeDownSocialreinsAction::class)->handle($post->fresh());
        $this->assertStringContainsString('public network addresses', $problems[0]);

        $retry = $this->makePost($post->workspace, ['facebook' => ['caption' => 'Again']], ['platforms' => ['facebook']]);
        $this->assertSame('failed', $this->publish($retry)->publish_state);

        Http::assertSentCount($sent);
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
        Http::fake([self::URL => Http::response($this->groupResponse(['facebook']), 201)]);
        $post = $this->makePost($this->workspace(), ['facebook' => ['caption' => 'Hello']], ['platforms' => ['facebook']]);
        $this->publish($post);

        $this->expectException(ValidationException::class);
        app(EnqueueSocialreinsPublishAction::class)->handle($post->fresh(), $post->workspace, []);
    }

    /** @return array<string, mixed> */
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
