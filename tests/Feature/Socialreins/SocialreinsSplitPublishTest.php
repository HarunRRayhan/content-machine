<?php

namespace Tests\Feature\Socialreins;

use App\Actions\Postsyncer\EnqueuePostPublishAction;
use App\Actions\Postsyncer\PublishPostAction;
use App\Actions\Postsyncer\SyncScheduledPostsAction;
use App\Actions\Socialreins\PublishToSocialreinsAction;
use App\Actions\Socialreins\SettleSocialreinsSubjectAction;
use App\Actions\Socialreins\TakeDownSocialreinsAction;
use App\Actions\Telegram\CancelTelegramPostRequestAction;
use App\Jobs\PublishPostJob;
use App\Jobs\PublishSocialreinsJob;
use App\Models\Post;
use App\Models\SocialreinsGroup;
use App\Models\TelegramBotConfig;
use App\Models\TelegramBotLink;
use App\Models\TelegramPostRequest;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Socialreins\SocialreinsConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakesPublicDns;
use Tests\TestCase;

/** One post published through both drivers: PostSyncer first, then SocialReins for the platforms it covers. */
class SocialreinsSplitPublishTest extends TestCase
{
    use FakesPublicDns, RefreshDatabase;

    private const SR_URL = 'https://brain.test/v1/post-groups';

    private const PS_URL = 'https://postsyncer.com/api/v1/posts';

    private const SET = '11111111-1111-4111-8111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolveHostsTo();
        Queue::fake();
    }

    /** @param  list<string>|null  $covered  SocialReins platforms for english; null leaves the list unset */
    private function workspace(?array $covered = ['facebook'], bool $linkedinAccount = true): Workspace
    {
        $workspace = Workspace::factory()->create();
        PostsyncerConfig::write($workspace, [
            'api_key' => 'k',
            'publish_enabled' => true,
            'video_publish_enabled' => true,
            'default_language' => 'english',
            'languages' => ['english' => [
                'workspace_id' => '853',
                'platforms' => [
                    'facebook' => ['account_id' => 100, 'handle' => '@fb'],
                    ...($linkedinAccount ? ['linkedin' => ['account_id' => 102, 'handle' => '@li']] : []),
                ],
            ]],
            'post_types' => ['platforms' => [
                'facebook' => ['text' => 'on', 'photo' => 'on', 'reel' => 'on'],
                'linkedin' => ['text' => 'on', 'photo' => 'on', 'reel' => 'on'],
            ]],
        ]);
        SocialreinsConfig::write($workspace, [
            'publish_driver' => 'socialreins',
            'base_url' => 'https://brain.test/v1',
            'default_language' => 'english',
            'languages' => ['english' => array_filter([
                'set_id' => self::SET,
                'token' => 'sr_set_live_english',
                'platforms' => $covered,
            ], fn (mixed $value): bool => $value !== null)],
        ]);

        return $workspace->fresh();
    }

    private function makePost(Workspace $workspace): Post
    {
        return Post::factory()->for($workspace)->create([
            'language' => 'en',
            'platforms' => ['facebook', 'linkedin'],
            'captions' => ['English' => [
                'facebook' => ['caption' => 'EN fb'],
                'linkedin' => ['caption' => 'EN li'],
            ]],
            'image_drive_urls' => [],
            'status' => 'ready',
        ]);
    }

    /** @param  list<mixed>  $srAnswers  brain answers for successive `POST /post-groups` */
    private function fakeApis(array $srAnswers = [], int $psCreateStatus = 201): void
    {
        $sr = Http::sequence();
        foreach ($srAnswers ?: [[$this->srGroup(), 201]] as [$body, $status]) {
            $sr->push($body, $status);
        }

        $this->fakeHttp([
            self::SR_URL => $sr,
            self::PS_URL => Http::response(['id' => 42, 'status' => 'published'], $psCreateStatus),
            self::PS_URL.'/42' => Http::response([
                'id' => 42,
                'workspace_id' => 853,
                'content' => [['text' => 'EN li', 'media' => []]],
                'platforms' => [['platform' => 'linkedin', 'account_id' => 102, 'settings' => []]],
                'status' => 'PUBLISHED',
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    private function srGroup(): array
    {
        return [
            'group_id' => 'aaaaaaaa-0000-4000-8000-000000000001',
            'expected_targets' => 1,
            'posts' => [[
                'id' => 'bbbbbbbb-0000-4000-8000-000000000001',
                'platform' => 'facebook',
                'status' => 'scheduled',
                'error' => null,
                'targets' => [],
            ]],
        ];
    }

    /** Run every queued publish job once, as the worker would, oldest first. */
    private function runQueued(): void
    {
        $ran = [];
        do {
            $jobs = collect(Queue::pushedJobs())->flatten(1)->pluck('job')
                ->reject(fn (object $job): bool => in_array(spl_object_id($job), $ran, true));
            foreach ($jobs as $job) {
                $ran[] = spl_object_id($job);
                match (true) {
                    $job instanceof PublishPostJob => $job->handle(app(PublishPostAction::class)),
                    $job instanceof PublishSocialreinsJob => $job->handle(app(PublishToSocialreinsAction::class)),
                    default => null,
                };
            }
        } while ($jobs->isNotEmpty());
    }

    /** @return list<Request> */
    private function sentTo(string $url): array
    {
        return Http::recorded()->map(fn (array $pair): Request => $pair[0])
            ->filter(fn (Request $r): bool => $r->method() === 'POST' && $r->url() === $url)->values()->all();
    }

    private function publish(Post $post, array $options = []): Post
    {
        return app(EnqueuePostPublishAction::class)->handle($post, $post->workspace->fresh(), $options);
    }

    public function test_a_split_post_creates_postsyncer_groups_and_socialreins_rows_only_for_their_own_platforms(): void
    {
        $this->fakeApis();
        $post = $this->makePost($this->workspace());

        $queued = $this->publish($post);

        Queue::assertPushed(PublishPostJob::class, 1);
        Queue::assertNotPushed(PublishSocialreinsJob::class);
        $this->assertSame(['linkedin'], $queued->publish_progress['options']['platforms']);
        $this->assertSame(['facebook'], $queued->publish_progress['split']['socialreins']['platforms']);

        $this->runQueued();

        [$psCreate] = $this->sentTo(self::PS_URL);
        $this->assertStringContainsString('EN li', $psCreate->body());
        $this->assertStringNotContainsString('EN fb', $psCreate->body());
        $this->assertCount(1, $this->sentTo(self::PS_URL));

        [$srCreate] = $this->sentTo(self::SR_URL);
        $this->assertSame(['facebook'], $srCreate['platforms']);
        $this->assertCount(1, $this->sentTo(self::SR_URL));

        $post->refresh();
        $this->assertSame('succeeded', $post->publish_state);
        $this->assertSame('socialreins', $post->publish_progress['driver']);
        $this->assertSame('succeeded', $post->publish_progress['state']);
        $this->assertSame(['linkedin'], $post->postsyncer['groups'][0]['platforms']);
        // Kept for a later reschedule of the PostSyncer half.
        $this->assertSame('42', (string) $post->publish_progress['split']['completed_groups'][0]['post_id']);
        $this->assertSame(1, SocialreinsGroup::query()->where('state', 'created')->count());
        $this->assertTrue(SocialreinsConfig::routesToSocialreins($post, $post->workspace));
    }

    public function test_a_socialreins_failure_after_postsyncer_retries_only_socialreins(): void
    {
        $this->fakeApis([[['error' => 'too_long'], 422], [$this->srGroup(), 201]]);
        $post = $this->makePost($this->workspace());

        $this->publish($post);
        $this->runQueued();

        $post->refresh();
        $this->assertSame('failed', $post->publish_state);
        $this->assertSame('failed', $post->publish_progress['state']);
        $this->assertNotEmpty($post->postsyncer['groups']);
        $this->assertTrue($post->canRetryPublish());

        // The retry dialog may send any platforms: only the SocialReins half goes out.
        $this->publish($post, ['platforms' => ['facebook', 'linkedin']]);
        $this->runQueued();

        $this->assertCount(1, $this->sentTo(self::PS_URL));
        $srCreates = $this->sentTo(self::SR_URL);
        $this->assertCount(2, $srCreates);
        $this->assertSame(['facebook'], $srCreates[1]['platforms']);
        $post->refresh();
        $this->assertSame('succeeded', $post->publish_state);
        $this->assertSame(['linkedin'], $post->publish_progress['split']['postsyncer']);
    }

    public function test_a_postsyncer_failure_retries_postsyncer_then_hands_over_once(): void
    {
        $this->fakeApis();
        $workspace = $this->workspace(linkedinAccount: false);
        $post = $this->makePost($workspace);

        $this->publish($post);
        $this->runQueued();

        $post->refresh();
        $this->assertSame('failed', $post->publish_state);
        $this->assertArrayNotHasKey('driver', $post->publish_progress);
        $this->assertSame([], $this->sentTo(self::PS_URL));
        $this->assertSame([], $this->sentTo(self::SR_URL));

        PostsyncerConfig::write($workspace, ['languages' => ['english' => [
            'workspace_id' => '853',
            'platforms' => [
                'facebook' => ['account_id' => 100, 'handle' => '@fb'],
                'linkedin' => ['account_id' => 102, 'handle' => '@li'],
            ],
        ]]]);

        $this->publish($post->fresh());
        $this->runQueued();

        $this->assertCount(1, $this->sentTo(self::PS_URL));
        $this->assertCount(1, $this->sentTo(self::SR_URL));
        $this->assertSame('succeeded', $post->fresh()->publish_state);
    }

    public function test_when_socialreins_covers_every_platform_the_post_goes_whole_to_socialreins(): void
    {
        $queued = $this->publish($this->makePost($this->workspace(['facebook', 'linkedin'])));

        Queue::assertPushed(PublishSocialreinsJob::class, fn (PublishSocialreinsJob $job): bool => ! isset($job->options['platforms']));
        Queue::assertNotPushed(PublishPostJob::class);
        $this->assertArrayNotHasKey('split', $queued->publish_progress);
    }

    public function test_without_a_platform_list_the_post_goes_whole_to_socialreins_as_before(): void
    {
        $this->publish($this->makePost($this->workspace(null)));

        Queue::assertPushed(PublishSocialreinsJob::class, 1);
        Queue::assertNotPushed(PublishPostJob::class);
    }

    public function test_when_socialreins_covers_none_of_its_platforms_the_post_goes_whole_to_postsyncer(): void
    {
        $queued = $this->publish($this->makePost($this->workspace(['twitter'])));

        Queue::assertPushed(PublishPostJob::class, fn (PublishPostJob $job): bool => ! isset($job->options['platforms']));
        Queue::assertNotPushed(PublishSocialreinsJob::class);
        $this->assertArrayNotHasKey('split', $queued->publish_progress);
    }

    public function test_a_post_already_on_postsyncer_keeps_retrying_there_whole(): void
    {
        $post = $this->makePost($this->workspace());
        $post->forceFill([
            'publish_state' => 'failed',
            'publish_progress' => [
                'version' => 1,
                'operation_id' => 'op-1',
                'run_token' => 'run-1',
                'options' => [],
                'plan_hash' => null,
                'planned_groups' => [],
                'completed_groups' => [],
                'current' => null,
                'state' => 'failed',
            ],
        ])->save();

        $queued = $this->publish($post);

        Queue::assertPushed(PublishPostJob::class, 1);
        Queue::assertNotPushed(PublishSocialreinsJob::class);
        $this->assertSame([], $queued->publish_progress['options']);
        $this->assertArrayNotHasKey('split', $queued->publish_progress);
        $this->assertNull(SocialreinsConfig::splitOf($queued, $queued->workspace));
    }

    private function telegramRequest(Post $post): TelegramPostRequest
    {
        $config = TelegramBotConfig::factory()->for($post->workspace)->connected()->create();
        TelegramBotLink::factory()->create(['telegram_bot_config_id' => $config->id, 'telegram_user_id' => 42]);

        return TelegramPostRequest::factory()->for($post->workspace)->create([
            'telegram_bot_config_id' => $config->id,
            'telegram_user_id' => 42,
            'telegram_chat_id' => 555,
            'post_id' => $post->id,
            'state' => TelegramPostRequest::APPROVED,
        ]);
    }

    /** @return array<string, array{list<string>|null}> */
    public static function routedLists(): array
    {
        return ['no routed list' => [null], 'a routed list' => [['english']]];
    }

    /** @param  list<string>|null  $routed */
    #[DataProvider('routedLists')]
    public function test_a_telegram_publish_routed_whole_to_socialreins_closes_its_request_as_published(?array $routed): void
    {
        $this->fakeApis();
        $workspace = $this->workspace(['facebook', 'linkedin']);
        if ($routed !== null) {
            SocialreinsConfig::write($workspace, ['routed_languages' => $routed]);
        }
        $post = $this->makePost($workspace->fresh());
        $request = $this->telegramRequest($post);

        $queued = $this->publish($post, ['telegram_request_id' => $request->id]);

        Queue::assertNotPushed(PublishPostJob::class);
        Queue::assertPushed(PublishSocialreinsJob::class, fn (PublishSocialreinsJob $job): bool => $job->options['telegram_request_id'] === $request->id);
        $this->assertSame($request->id, $queued->publish_progress['options']['telegram_request_id']);
        $this->assertSame(TelegramPostRequest::APPROVED, $request->fresh()->state);

        $this->runQueued();

        // Handed over and scheduled counts as published, as on PostSyncer.
        $this->assertSame('scheduled', $post->fresh()->status);
        $this->assertSame(TelegramPostRequest::PUBLISHED, $request->fresh()->state);

        // /cancel finds nothing to cancel: the scheduled post stays, and so does the request.
        app(CancelTelegramPostRequestAction::class)->handle($request, $request->telegramBotConfig, 42, 555);
        $this->assertSame(TelegramPostRequest::PUBLISHED, $request->fresh()->state);
    }

    public function test_a_failed_socialreins_telegram_publish_fails_its_request_and_a_retry_reopens_it(): void
    {
        $this->fakeApis([[['error' => 'too_long'], 422], [$this->srGroup(), 201]]);
        $post = $this->makePost($this->workspace(['facebook', 'linkedin']));
        $request = $this->telegramRequest($post);

        $this->publish($post, ['telegram_request_id' => $request->id]);
        $this->runQueued();

        $this->assertSame('failed', $post->fresh()->publish_state);
        $this->assertSame(TelegramPostRequest::FAILED, $request->fresh()->state);
        $this->assertNotNull($request->fresh()->error_message);

        // A dashboard retry carries no request id: the post's failed request is picked up.
        $queued = $this->publish($post->fresh());

        $this->assertSame(TelegramPostRequest::APPROVED, $request->fresh()->state);
        $this->assertNull($request->fresh()->error_message);
        $this->assertSame($request->id, $queued->publish_progress['options']['telegram_request_id']);

        $this->runQueued();

        $this->assertSame('succeeded', $post->fresh()->publish_state);
        $this->assertSame(TelegramPostRequest::PUBLISHED, $request->fresh()->state);
    }

    public function test_a_settle_after_the_job_still_finds_the_request(): void
    {
        $this->fakeApis();
        $post = $this->makePost($this->workspace(['facebook', 'linkedin']));
        $request = $this->telegramRequest($post);
        $this->publish($post, ['telegram_request_id' => $request->id]);
        $this->runQueued();
        $request->forceFill(['state' => TelegramPostRequest::FAILED])->save();

        // What a webhook or the reconcile runs: no publish options, only the stored ones.
        app(SettleSocialreinsSubjectAction::class)->handle($post->fresh());

        $this->assertSame(TelegramPostRequest::PUBLISHED, $request->fresh()->state);
    }

    public function test_a_cancelled_telegram_request_is_not_published_through_socialreins(): void
    {
        $this->fakeApis();
        $post = $this->makePost($this->workspace(['facebook', 'linkedin']));
        $request = $this->telegramRequest($post);
        $this->publish($post, ['telegram_request_id' => $request->id]);
        $request->forceFill(['state' => TelegramPostRequest::CANCELLED])->save();

        $this->runQueued();

        $this->assertSame([], $this->sentTo(self::SR_URL));
        $this->assertSame('idle', $post->fresh()->publish_state);
        $this->assertSame(TelegramPostRequest::CANCELLED, $request->fresh()->state);
    }

    public function test_a_socialreins_job_that_fails_for_good_fails_the_telegram_request(): void
    {
        $post = $this->makePost($this->workspace(['facebook', 'linkedin']));
        $request = $this->telegramRequest($post);
        $queued = $this->publish($post, ['telegram_request_id' => $request->id]);

        (new PublishSocialreinsJob($queued, $queued->publish_progress['options']))->failed(null);

        $this->assertSame('failed', $post->fresh()->publish_state);
        $this->assertSame(TelegramPostRequest::FAILED, $request->fresh()->state);
    }

    public function test_a_split_telegram_publish_closes_its_request_only_after_both_halves(): void
    {
        $this->fakeApis();
        $post = $this->makePost($this->workspace());
        $request = $this->telegramRequest($post);

        $queued = $this->publish($post, ['telegram_request_id' => $request->id]);

        $this->assertSame($request->id, $queued->publish_progress['split']['socialreins']['telegram_request_id']);
        Queue::assertPushed(PublishPostJob::class, 1);

        // PostSyncer's half alone: handed over, the request is still open and cannot be cancelled.
        Queue::pushed(PublishPostJob::class)->first()->handle(app(PublishPostAction::class));

        $this->assertCount(1, $this->sentTo(self::PS_URL));
        $this->assertSame('queued', $post->fresh()->publish_state);
        $this->assertSame(TelegramPostRequest::APPROVED, $request->fresh()->state);
        app(CancelTelegramPostRequestAction::class)->handle($request, $request->telegramBotConfig, 42, 555);
        $this->assertSame(TelegramPostRequest::APPROVED, $request->fresh()->state);

        $this->runQueued();

        $this->assertCount(1, $this->sentTo(self::PS_URL));
        $this->assertCount(1, $this->sentTo(self::SR_URL));
        $this->assertSame('succeeded', $post->fresh()->publish_state);
        $this->assertSame(TelegramPostRequest::PUBLISHED, $request->fresh()->state);
    }

    public function test_a_split_telegram_publish_fails_its_request_when_the_socialreins_half_fails(): void
    {
        $this->fakeApis([[['error' => 'too_long'], 422], [$this->srGroup(), 201]]);
        $post = $this->makePost($this->workspace());
        $request = $this->telegramRequest($post);

        $this->publish($post, ['telegram_request_id' => $request->id]);
        $this->runQueued();

        $this->assertSame('failed', $post->fresh()->publish_state);
        $this->assertSame(TelegramPostRequest::FAILED, $request->fresh()->state);

        // The bot's /post retry names the request; only the SocialReins half goes out again.
        $this->publish($post->fresh(), ['confirm_ask' => true, 'telegram_request_id' => $request->id]);
        $this->runQueued();

        $this->assertCount(1, $this->sentTo(self::PS_URL));
        $this->assertSame('succeeded', $post->fresh()->publish_state);
        $this->assertSame(TelegramPostRequest::PUBLISHED, $request->fresh()->state);
    }

    public function test_a_split_telegram_publish_fails_its_request_when_the_postsyncer_half_fails(): void
    {
        $this->fakeApis();
        $post = $this->makePost($this->workspace(linkedinAccount: false));
        $request = $this->telegramRequest($post);

        $this->publish($post, ['telegram_request_id' => $request->id]);
        $this->runQueued();

        $this->assertSame([], $this->sentTo(self::SR_URL));
        $this->assertSame('failed', $post->fresh()->publish_state);
        $this->assertSame(TelegramPostRequest::FAILED, $request->fresh()->state);
    }

    public function test_the_postsyncer_sync_does_not_mark_a_split_post_posted_while_its_socialreins_half_failed(): void
    {
        $this->fakeApis([[['error' => 'too_long'], 422]]);
        $post = $this->makePost($this->workspace());

        $this->publish($post);
        $this->runQueued();
        $post->refresh()->forceFill(['status' => 'scheduled'])->save();
        $this->assertSame('failed', $post->publish_state);

        app(SyncScheduledPostsAction::class)->handle();

        $post->refresh();
        $this->assertSame('PUBLISHED', $post->postsyncer['groups'][0]['status']);
        $this->assertSame('scheduled', $post->status);
    }

    public function test_take_down_reports_a_published_postsyncer_half(): void
    {
        $this->fakeApis();
        $post = $this->makePost($this->workspace());
        $this->publish($post);
        $this->runQueued();
        $before = $post->refresh()->status;

        $problems = app(TakeDownSocialreinsAction::class)->handle($post);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('PostSyncer', $problems[0]);
        $this->assertSame($before, $post->refresh()->status);
        $this->assertSame('succeeded', $post->publish_state);
    }
}
